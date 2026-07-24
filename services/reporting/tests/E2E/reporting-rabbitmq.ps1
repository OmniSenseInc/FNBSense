param(
    [string] $Database = 'fnbsense_reporting_test'
)

$ErrorActionPreference = 'Stop'

$reportingRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..\..')).Path
$probeId = [guid]::NewGuid().ToString()
$stdout = Join-Path $reportingRoot "storage\logs\reporting-e2e-$probeId.out.log"
$stderr = Join-Path $reportingRoot "storage\logs\reporting-e2e-$probeId.err.log"

$rootEnvironment = Get-Content (Join-Path $repositoryRoot '.env')
$rabbitUserLine = $rootEnvironment |
    Where-Object { $_ -match '^RABBITMQ_DEFAULT_USER=' } |
    Select-Object -First 1
$rabbitPasswordLine = $rootEnvironment |
    Where-Object { $_ -match '^RABBITMQ_DEFAULT_PASS=' } |
    Select-Object -First 1

if (-not $rabbitUserLine -or -not $rabbitPasswordLine) {
    throw 'Kredensial RabbitMQ tidak ditemukan di .env root.'
}

$rabbitUser = ($rabbitUserLine -split '=', 2)[1]
$rabbitPassword = ($rabbitPasswordLine -split '=', 2)[1]
$basicToken = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes("${rabbitUser}:$rabbitPassword")
)

$env:DB_DATABASE = $Database
$consumer = Start-Process `
    -FilePath 'php' `
    -ArgumentList @('artisan', 'reporting:consume') `
    -WorkingDirectory $reportingRoot `
    -RedirectStandardOutput $stdout `
    -RedirectStandardError $stderr `
    -PassThru `
    -WindowStyle Hidden

try {
    $ready = $false

    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $queues = docker exec fnbsense-rabbitmq rabbitmqctl list_queues name consumers 2>$null

        if ($queues -match 'reporting\.sales\s+1') {
            $ready = $true
            break
        }

        Start-Sleep -Seconds 1
    }

    if (-not $ready) {
        $errorOutput = if (Test-Path $stderr) { Get-Content $stderr -Raw } else { '' }
        throw "Consumer Reporting tidak siap. $errorOutput"
    }

    $eventId = [guid]::NewGuid().ToString()
    $orderId = [guid]::NewGuid().ToString()
    $tenantId = [guid]::NewGuid().ToString()
    $outletId = [guid]::NewGuid().ToString()
    $productId = [guid]::NewGuid().ToString()

    $event = @{
        event_id = $eventId
        event_type = 'order.paid'
        occurred_at = [DateTimeOffset]::Now.ToString('o')
        tenant_id = $tenantId
        outlet_id = $outletId
        payload = @{
            order_id = $orderId
            confirmed_by = [guid]::NewGuid().ToString()
            payment_method = 'cash'
            totals = @{
                subtotal = 25000
                service_charge = 0
                tax = 0
                grand_total = 25000
            }
            items = @(
                @{
                    product_id = $productId
                    product_name = 'F6 E2E Probe'
                    qty = 1
                    unit_price = 25000
                }
            )
        }
    } | ConvertTo-Json -Compress -Depth 10

    $publishBody = @{
        properties = @{
            delivery_mode = 2
            content_type = 'application/json'
        }
        routing_key = 'order.paid'
        payload = $event
        payload_encoding = 'string'
    } | ConvertTo-Json -Compress -Depth 10

    $publishResult = Invoke-RestMethod `
        -Method Post `
        -Uri 'http://127.0.0.1:15672/api/exchanges/%2F/fnbsense.events/publish' `
        -Headers @{ Authorization = "Basic $basicToken" } `
        -ContentType 'application/json' `
        -Body $publishBody
    $duplicatePublishResult = Invoke-RestMethod `
        -Method Post `
        -Uri 'http://127.0.0.1:15672/api/exchanges/%2F/fnbsense.events/publish' `
        -Headers @{ Authorization = "Basic $basicToken" } `
        -ContentType 'application/json' `
        -Body $publishBody

    $recorded = $false

    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $count = mysql `
            -h 127.0.0.1 `
            -P 3306 `
            -u root `
            -N `
            -B `
            -e "SELECT COUNT(*) FROM $Database.sales_facts WHERE order_id = '$orderId';"

        if ([int] $count -eq 1) {
            $recorded = $true
            break
        }

        Start-Sleep -Seconds 1
    }

    if (-not $recorded) {
        $errorOutput = if (Test-Path $stderr) { Get-Content $stderr -Raw } else { '' }
        throw "Event ter-publish tetapi read-model tidak muncul. $errorOutput"
    }

    $queueDrained = $false

    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $queueState = docker exec fnbsense-rabbitmq rabbitmqctl `
            list_queues name messages_ready messages_unacknowledged 2>$null

        if ($queueState -match 'reporting\.sales\s+0\s+0') {
            $queueDrained = $true
            break
        }

        Start-Sleep -Seconds 1
    }

    if (-not $queueDrained) {
        throw 'Queue reporting.sales tidak kosong setelah menunggu duplicate event diproses.'
    }

    $dedupCounts = mysql `
        -h 127.0.0.1 `
        -P 3306 `
        -u root `
        -N `
        -B `
        -e "SELECT (SELECT COUNT(*) FROM $Database.sales_facts WHERE order_id = '$orderId'), (SELECT COUNT(*) FROM $Database.processed_events WHERE event_id = '$eventId');"

    if ($dedupCounts -ne "1`t1") {
        throw "Idempotensi E2E gagal: count sales/processed = $dedupCounts."
    }

    $fact = mysql `
        -h 127.0.0.1 `
        -P 3306 `
        -u root `
        -N `
        -B `
        -e "SELECT grand_total, payment_method FROM $Database.sales_facts WHERE order_id = '$orderId';"

    [PSCustomObject]@{
        RabbitRouted = [bool] $publishResult.routed
        DuplicateRouted = [bool] $duplicatePublishResult.routed
        ReadModelRecorded = $recorded
        QueueDrained = $queueDrained
        DedupCounts = $dedupCounts
        OrderId = $orderId
        Fact = $fact
        ConsumerQueue = 'reporting.sales'
        Database = $Database
    }
}
finally {
    if ($consumer -and -not $consumer.HasExited) {
        Stop-Process -Id $consumer.Id
        Wait-Process -Id $consumer.Id -ErrorAction SilentlyContinue
    }

    Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue
}
