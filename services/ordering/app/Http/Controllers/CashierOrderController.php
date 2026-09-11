<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\StoreCashierOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outbox;
use App\Models\Table;
use App\Services\CatalogClient;
use App\Services\OrderPlacement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Endpoint kasir — antrean & keputusan uang (langkah 8).
 *
 * Semua query di-scope tenant_id + outlet_id dari klaim JWT: order milik outlet
 * lain secara struktural tak terlihat (404, bukan 403 — jangan bocorkan
 * keberadaannya). Transisi status memakai lockForUpdate() di dalam transaksi,
 * bukan cek-lalu-tulis, supaya dua kasir yang menekan bersamaan tak balapan.
 */
class CashierOrderController extends Controller
{
    /**
     * Antrean order outlet ini. Filter opsional: status, table_id.
     */
    public function index(ListOrdersRequest $request): JsonResponse
    {
        $orders = Order::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->when(
                $request->query('status'),
                fn ($query, $status) => $query->where('status', $status),
            )
            ->when(
                $request->query('table_id'),
                fn ($query, $tableId) => $query->where('table_id', $tableId),
            )
            // Batas bawah waktu BAYAR — dipakai layar riwayat supaya ia tak
            // mengunduh seluruh sejarah outlet tiap kali dibuka.
            //
            // Menyaring confirmed_at, bukan created_at: yang dicari kasir adalah
            // "dibayar hari ini", dan pesanan kemarin yang baru dilunasi pagi ini
            // memang termasuk. Konsekuensi yang disengaja: dipakai bersama
            // status=pending hasilnya selalu kosong, sebab pesanan yang belum
            // dibayar tak punya confirmed_at sama sekali.
            ->when(
                $request->query('paid_since'),
                // Diurai jadi Carbon dulu, tidak disuap sebagai string apa
                // adanya: klien mengirim ISO-8601 ber-offset ("...T17:00:00Z"),
                // dan MySQL membandingkan bentuk itu terhadap kolom DATETIME
                // tanpa mengeluh — cuma hasilnya yang meleset sejauh offsetnya.
                // Aman karena 'date' di ListOrdersRequest sudah menyaring lebih
                // dulu, jadi parse() di sini tak pernah menerima sampah.
                fn ($query, $sejak) => $query->where('confirmed_at', '>=', Carbon::parse($sejak)),
            )
            // `table` di-eager-load, bukan dibiarkan lazy: tanpa ini satu antrean
            // berisi 20 pesanan menembak 20 query tambahan hanya untuk mengambil
            // 20 label meja.
            ->with(['items', 'table'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->present($order))->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $order = $this->findScoped($request, $id)->load(['items', 'table']);

        return response()->json(['data' => $this->present($order)]);
    }

    /**
     * Menu untuk POS kasir (kategori -> produk + penanda habis).
     *
     * Kasir membaca menu yang sama dengan pelanggan, TAPI scoped outlet supaya
     * produk yang bahannya habis di outlet ini ikut tertandai — kasir tak boleh
     * menawarkan barang yang gerbang stoknya bakal menolak saat order dibuat.
     */
    public function menu(Request $request, CatalogClient $catalog): JsonResponse
    {
        return response()->json([
            'data' => $catalog->menu($this->tenantId($request), $this->outletId($request)),
        ]);
    }

    /**
     * Daftar meja AKTIF outlet ini (id + label), buat POS memilih meja dine-in.
     * Tanpa qr_token: kasir tak perlu kredensial cetak meja.
     */
    public function tables(Request $request): JsonResponse
    {
        $tables = Table::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label']);

        return response()->json(['data' => $tables]);
    }

    /**
     * Buat order POS oleh kasir (walk-in / telepon / meja). Order PENDING masuk
     * antrean — kasir lalu mengonfirmasi pembayaran seperti order QR biasa.
     *
     * Harga & total dihitung server (OrderPlacement), tak pernah dari client.
     */
    public function store(StoreCashierOrderRequest $request, OrderPlacement $placement): JsonResponse
    {
        $data = $request->validated();
        $tableId = $data['table_id'] ?? null;

        if ($data['order_type'] === OrderType::DineIn->value) {
            // Meja wajib ada & aktif & milik outlet ini. 422 (bukan 404): kasir
            // memilih dari daftar yang sudah di-scope, jadi "tak ada" berarti
            // daftarnya basi — bukan penyusup yang menebak id.
            $tableId = Table::query()
                ->where('tenant_id', $this->tenantId($request))
                ->where('outlet_id', $this->outletId($request))
                ->where('is_active', true)
                ->where('id', $tableId)
                ->value('id');

            if ($tableId === null) {
                throw new HttpException(422, 'Pilih meja yang valid untuk pesanan dine-in.');
            }
        }

        $order = $placement->place(
            $this->tenantId($request),
            $this->outletId($request),
            $tableId,
            $data['order_type'],
            $data['customer_name'] ?? '',
            $data['items'],
        );

        return response()->json(['data' => $this->present($order->load(['items', 'table']))], 201);
    }

    /**
     * Konfirmasi pembayaran: PENDING -> PAID + satu baris outbox, dalam SATU
     * transaksi. Idempoten: order yang sudah PAID membalas state sekarang tanpa
     * menulis outbox kedua (pertahanan utama double-charge). CANCELLED -> 409
     * (transisi tak sah). EXPIRED tetap boleh dibayar kalau uang sungguh masuk.
     */
    public function confirmPayment(ConfirmPaymentRequest $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $outletId = $this->outletId($request);
        $userId = $this->userId($request);
        $paymentMethod = $request->validated()['payment_method'];

        $order = DB::transaction(function () use ($id, $tenantId, $outletId, $userId, $paymentMethod) {
            // lockForUpdate: request kedua yang bersamaan menunggu di sini sampai
            // yang pertama commit, lalu membaca status yang SUDAH berubah -> cabang
            // idempoten di bawah. Tanpa lock, dua-duanya bisa lihat PENDING.
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('outlet_id', $outletId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new ModelNotFoundException;
            }

            // Sudah PAID -> tak menulis apa-apa, kembalikan apa adanya. PAID terminal.
            if ($order->status === OrderStatus::Paid) {
                return $order;
            }

            // PENDING dan EXPIRED sama-sama boleh menjadi PAID. EXPIRED bukan
            // pagar uang — ia cuma pembersih antrean pelanggan (order yang
            // ditinggal tanpa bayar). Kalau uang sungguh masuk (kasir melihat
            // mutasi QRIS-nya), penjualan itu WAJIB bisa dicatat: menolaknya di
            // sini membuat duit nyangkut tanpa jalur pemulihan — stok tak
            // terpotong, penjualan tak tercatat, dan owner tak akan tahu.
            // CANCELLED tetap 409: order yang dibatalkan memang tak pernah
            // dibayar, dan menghidupkannya kembali membohongi dapur.
            if ($order->status !== OrderStatus::Pending && $order->status !== OrderStatus::Expired) {
                throw new HttpException(409, 'Order tidak lagi bisa dibayar.');
            }

            $order->status = OrderStatus::Paid;
            $order->payment_method = $paymentMethod;
            $order->confirmed_by = $userId;
            $order->confirmed_at = now();
            $order->save();

            $this->writeOutbox($order->load('items'), $tenantId, $outletId);

            return $order;
        });

        return response()->json(['data' => $this->present($order->load(['items', 'table']))]);
    }

    /**
     * Tandai pesanan siap diantar.
     *
     * Hanya pesanan yang SUDAH dibayar: menandai siap pesanan yang belum lunas
     * berarti barangnya keluar tanpa uang, dan urutan itu tak bisa diperbaiki
     * belakangan.
     *
     * Idempoten — sudah ditandai membalas apa adanya tanpa menggeser jamnya.
     * Jam siap yang bergeser tiap ketukan membuat "siap sejak 14:41" berbohong,
     * dan pelanggan yang menunggu 10 menit melihat pesanannya seolah baru saja
     * selesai.
     *
     * Diisi kasir hari ini. Saat layar dapur (KDS) jadi, ia memakai endpoint
     * INI juga — bukan jalur kedua yang harus sama-sama benar.
     */
    public function markReady(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $outletId = $this->outletId($request);

        $order = DB::transaction(function () use ($id, $tenantId, $outletId) {
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('outlet_id', $outletId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new ModelNotFoundException;
            }

            if ($order->status !== OrderStatus::Paid) {
                throw new HttpException(409, 'Hanya pesanan yang sudah dibayar bisa ditandai siap.');
            }

            if ($order->ready_at === null) {
                $order->ready_at = now();
                $order->save();
            }

            return $order;
        });

        return response()->json(['data' => $this->present($order->load(['items', 'table']))]);
    }

    /**
     * Batalkan order PENDING. PAID terminal -> 409 (uang sudah masuk, tak bisa
     * dibatalkan). Sudah CANCELLED/EXPIRED -> 409 (tak ada yang perlu diubah).
     */
    public function cancel(CancelOrderRequest $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $outletId = $this->outletId($request);
        $reason = $request->validated()['reason'] ?? null;
        $userId = $this->userId($request);

        $order = DB::transaction(function () use ($id, $tenantId, $outletId, $reason, $userId) {
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('outlet_id', $outletId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new ModelNotFoundException;
            }

            if ($order->status !== OrderStatus::Pending) {
                throw new HttpException(409, 'Order tidak lagi bisa dibatalkan.');
            }

            $occurredAt = Carbon::now();

            $order->status = OrderStatus::Cancelled;
            // Siapa yang membatalkan. Owner tak punya cara lain untuk tahu:
            // pesanan batal hilang dari antrean tanpa meninggalkan pelaku.
            $order->cancelled_by = $userId;
            // order.note tak diisi saat order dibuat (customer tak mengirim note
            // tingkat order), jadi aman menyimpan alasan pembatalan di sini.
            if ($reason !== null) {
                $order->note = $reason;
            }
            $order->save();

            // Di DALAM transaksi, bersama perubahan statusnya. Kalau di luar,
            // ada celah di mana pesanan sudah batal tapi eventnya tak pernah
            // lahir — dan owner tak akan pernah tahu, tanpa satu pun jejak
            // bahwa ada yang hilang.
            $this->recordCancelled($order, $tenantId, $outletId, $reason, $userId, $occurredAt);

            return $order;
        });

        return response()->json(['data' => $this->present($order->load(['items', 'table']))]);
    }

    /**
     * Order milik outlet ini, atau 404. Scope tenant+outlet = isolasi: order
     * outlet lain tak pernah terlihat.
     */
    private function findScoped(Request $request, string $id): Order
    {
        return Order::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Tulis baris outbox berisi AMPLOP event `order.paid` LENGKAP & siap-kirim
     * (Opsi B): relay worker nanti (F3/F4) tinggal mengangkat & mem-publish apa
     * adanya, tak perlu menyusun ulang. Kolom event_type/occurred_at/aggregate_id
     * diisi sebagai metadata agar relay bisa memindai tanpa mem-parse JSON.
     *
     * Bentuk mengikuti shared/contracts/events/order-paid.event.json — semua
     * nilai uang integer rupiah.
     */
    private function writeOutbox(Order $order, string $tenantId, string $outletId): void
    {
        $occurredAt = now();

        $payload = [
            'order_id' => $order->id,
            'order_type' => $order->order_type->value,
            'confirmed_by' => $order->confirmed_by,
            'payment_method' => $order->payment_method->value,
            'totals' => [
                'gross_subtotal' => (int) $order->gross_subtotal,
                'discount_total' => (int) $order->discount_total,
                'subtotal' => (int) $order->subtotal,
                'service_charge' => (int) $order->service_charge,
                'tax' => (int) $order->tax,
                'grand_total' => (int) $order->grand_total,
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'qty' => (int) $item->qty,
                'unit_price' => (int) $item->unit_price,
                'unit_cost' => (int) $item->unit_cost,
            ])->all(),
        ];

        if ($order->promotion_snapshot !== null) {
            $payload['promotion'] = $order->promotion_snapshot;
        }

        // table_id opsional di kontrak; takeaway tak punya meja. Jangan kirim
        // null untuk field bertipe string — cukup hilangkan kuncinya.
        if ($order->table_id !== null) {
            $payload['table_id'] = $order->table_id;
        }

        Outbox::create([
            'aggregate_type' => 'order',
            'aggregate_id' => $order->id,
            'event_type' => 'order.paid',
            'payload' => [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'order.paid',
                'occurred_at' => $occurredAt->toIso8601String(),
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'payload' => $payload,
            ],
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Catat pembatalan ke outbox, untuk diteruskan relay ke Notification.
     *
     * Nominalnya ikut dikirim. Owner yang melihat "pesanan dibatalkan" tanpa
     * angka tak bisa membedakan segelas kopi yang salah pesan dari rombongan
     * dua juta yang batal — dan itu justru satu-satunya alasan ia perlu
     * diberi tahu sama sekali.
     */
    private function recordCancelled(
        Order $order,
        string $tenantId,
        string $outletId,
        ?string $reason,
        string $userId,
        Carbon $occurredAt,
    ): void {
        Outbox::create([
            'aggregate_type' => 'order',
            'aggregate_id' => $order->id,
            'event_type' => 'order.cancelled',
            'payload' => [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'order.cancelled',
                'occurred_at' => $occurredAt->toIso8601String(),
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'payload' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'grand_total' => (int) $order->grand_total,
                    'reason' => $reason,
                    'cancelled_by' => $userId,
                ],
            ],
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Bentuk order untuk kasir. Berbeda dari present() customer: kasir BOLEH
     * melihat field internal (payment_method, confirmed_by/at, tarif snapshot) —
     * ini konsol operasional, bukan polling publik.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'table_id' => $order->table_id,
            // Label meja, bukan model Table-nya. Yang dikirim dipilih satu per
            // satu supaya `qr_token` — kredensial cetak yang membuka meja itu
            // bagi siapa pun yang memegangnya — tak pernah punya jalan ikut
            // terserialisasi ke layar kasir.
            //
            // null untuk takeaway (tak punya meja) DAN untuk meja yang sudah
            // dihapus. Kasir tak boleh disuguhi tebakan; layar yang memutuskan
            // apa yang ditulis saat labelnya tak ada.
            'table_label' => $order->table?->label,
            'gross_subtotal' => $order->gross_subtotal,
            'discount_total' => $order->discount_total,
            'subtotal' => $order->subtotal,
            'service_charge' => $order->service_charge,
            'tax' => $order->tax,
            'grand_total' => $order->grand_total,
            'tax_percent' => $order->tax_percent,
            'service_charge_percent' => $order->service_charge_percent,
            'promotion' => $order->promotion_snapshot,
            'payment_method' => $order->payment_method,
            // Petunjuk untuk kasir, BUKAN isian yang sudah terkunci: pelanggan
            // boleh berubah pikiran di depan meja kasir, dan yang masuk laporan
            // harus yang benar-benar diterima.
            'payment_preference' => $order->payment_preference,
            // Jam pelanggan mengaku sudah mentransfer. Bukan bukti — kasir tetap
            // memeriksa notifikasi mutasinya sendiri; ini yang memberi tahu dia
            // pesanan MANA yang paling mungkin uangnya sudah masuk, dan sejak
            // pukul berapa harus mencarinya di daftar mutasi.
            'customer_claimed_paid_at' => $order->customer_claimed_paid_at,
            'confirmed_by' => $order->confirmed_by,
            'confirmed_at' => $order->confirmed_at,
            // null = masih dibuat. Layar kasir memakainya untuk memisahkan
            // "sedang dibuat" dari "sudah keluar", tanpa status tambahan.
            'ready_at' => $order->ready_at,
            'expires_at' => $order->expires_at,
            'note' => $order->note,
            // Siapa yang membatalkan, dan KAPAN. `updated_at` = mutasi terakhir,
            // dan pembatalan adalah mutasi terakhir yang mungkin terjadi — lihat
            // migrasi add_cancelled_by_to_orders_table. Untuk pesanan batal,
            // updated_at adalah jam pembatalannya. Dipakai layar "Pembatalan"
            // owner untuk mendeteksi void yang mencurigakan.
            'cancelled_by' => $order->cancelled_by,
            'updated_at' => $order->updated_at,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit_price' => $item->unit_price,
                'qty' => $item->qty,
                'line_total' => $item->line_total,
                'note' => $item->note,
            ])->all(),
        ];
    }
}
