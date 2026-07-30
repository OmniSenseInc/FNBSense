<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outbox;
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
    public function index(Request $request): JsonResponse
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
            ->with('items')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->present($order))->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $order = $this->findScoped($request, $id)->load('items');

        return response()->json(['data' => $this->present($order)]);
    }

    /**
     * Konfirmasi pembayaran: PENDING -> PAID + satu baris outbox, dalam SATU
     * transaksi. Idempoten: order yang sudah PAID membalas state sekarang tanpa
     * menulis outbox kedua (pertahanan utama double-charge). CANCELLED/EXPIRED
     * -> 409 (transisi tak sah).
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

            // Hanya PENDING yang boleh menjadi PAID.
            if ($order->status !== OrderStatus::Pending) {
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

        return response()->json(['data' => $this->present($order->load('items'))]);
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

        $order = DB::transaction(function () use ($id, $tenantId, $outletId, $reason) {
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

            $order->status = OrderStatus::Cancelled;
            // order.note tak diisi saat order dibuat (customer tak mengirim note
            // tingkat order), jadi aman menyimpan alasan pembatalan di sini.
            if ($reason !== null) {
                $order->note = $reason;
            }
            $order->save();

            return $order;
        });

        return response()->json(['data' => $this->present($order->load('items'))]);
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
            'confirmed_by' => $order->confirmed_by,
            'confirmed_at' => $order->confirmed_at,
            'expires_at' => $order->expires_at,
            'note' => $order->note,
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
