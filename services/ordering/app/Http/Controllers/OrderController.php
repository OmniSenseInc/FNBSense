<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSetting;
use App\Models\Table;
use App\Services\OrderPlacement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Endpoint customer — PUBLIK, tanpa login, rate-limited.
 *
 * Beda mendasar dari controller owner/kasir: tenant & outlet TIDAK dari klaim JWT
 * (customer tak punya token) melainkan diturunkan dari qr_token meja yang di-scan.
 * Itulah yang membuat customer secara STRUKTURAL tak bisa memesan lintas tenant
 * (skrutini #2): dia tak pernah menyebut tenant/outlet, hanya menunjuk meja.
 */
class OrderController extends Controller
{
    /**
     * Resolve QR meja -> identitas tenant/outlet/meja untuk frontend.
     *
     * Token tak dikenal / meja non-aktif -> 404 (firstOrFail). Sengaja 404, bukan
     * 403: jangan bocorkan bahwa token itu "ada tapi mati".
     */
    public function showTable(string $qrToken): JsonResponse
    {
        $table = Table::query()
            ->where('qr_token', $qrToken)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json(['data' => [
            'tenant_id' => $table->tenant_id,
            'outlet_id' => $table->outlet_id,
            'table_id' => $table->id,
            'label' => $table->label,
        ]]);
    }

    /**
     * Buat order PENDING. Harga & total DIHITUNG SERVER, tak pernah dari client.
     */
    public function store(
        StoreOrderRequest $request,
        OrderPlacement $placement,
    ): JsonResponse {
        $data = $request->validated();

        // Meja penentu tenant+outlet. Non-aktif/tak dikenal -> 404, bukan 422:
        // token bukan "input salah format" melainkan "meja tak ada / mati".
        $table = Table::query()
            ->where('qr_token', $data['qr_token'])
            ->where('is_active', true)
            ->firstOrFail();

        $order = $placement->place(
            $table->tenant_id,
            $table->outlet_id,
            $table->id,
            $data['order_type'],
            $data['customer_name'],
            $data['items'],
            $data['payment_preference'] ?? null,
        );

        return response()->json(['data' => $this->present($order)], 201);
    }

    /**
     * Polling status oleh customer. Field TERBATAS (skrutini): tanpa confirmed_by,
     * tenant_id, outlet_id — itu bukan urusan customer.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);

        return response()->json(['data' => $this->present($order)]);
    }

    /**
     * Pelanggan menyatakan sudah mentransfer lewat QRIS.
     *
     * Ini SINYAL, bukan gerbang. Kasir tetap bisa mengonfirmasi pesanan yang
     * tak pernah diklaim — pelanggan tunai tak akan pernah menekan tombolnya,
     * dan yang bayar QRIS pun sering langsung berdiri ke kasir sambil
     * menyodorkan bukti transfer. Menjadikan klaim sebagai syarat akan
     * membalik invarian inti: pelanggan yang menentukan kapan kasir boleh
     * menerima uang.
     *
     * Tanpa auth — pelanggan tak punya token. Yang bisa disalahgunakan orang
     * yang menebak `order_id` cuma menaikkan satu pesanan di antrean dan
     * memperpanjang tenggatnya sekali; bukan jalur uang, dan `show()` di atas
     * sudah publik dengan pemaparan setara.
     *
     * Sengaja TIDAK menolak pesanan yang niatnya tunai. Layar yang
     * menyembunyikan tombolnya (ia menumpang keputusan `qrisUntuk()`);
     * menambah cabang kedua di sini berarti dua tempat memutuskan hal yang
     * sama, dan keduanya bisa berselisih setelah salah satu diubah.
     */
    public function claimPaid(string $id): JsonResponse
    {
        $order = DB::transaction(function () use ($id) {
            // lockForUpdate: dua ketukan beruntun dari jari yang sama (atau tab
            // ganda) menunggu di sini, lalu yang kedua membaca kolom penanda
            // yang SUDAH terisi -> tak memperpanjang untuk kedua kalinya.
            $order = Order::query()->where('id', $id)->lockForUpdate()->first();

            if ($order === null) {
                throw new ModelNotFoundException;
            }

            // Sudah dibayar/batal/hangus -> tak ada yang perlu dilaporkan lagi.
            if ($order->status !== OrderStatus::Pending) {
                throw new HttpException(409, 'Pesanan ini tidak lagi menunggu pembayaran.');
            }

            // Klaim kedua dan seterusnya: balas keadaan sekarang, jangan tulis
            // apa pun. Kalau tenggat ikut diperpanjang tiap ketukan, satu orang
            // bisa menahan pesanannya di antrean kasir selamanya — pola yang
            // sama seperti "1 PENDING per meja" yang sudah ditolak.
            if ($order->customer_claimed_paid_at === null) {
                $setting = OrderSetting::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('outlet_id', $order->outlet_id)
                    ->first()
                    ?? OrderSetting::defaultsFor($order->tenant_id, $order->outlet_id);

                $order->customer_claimed_paid_at = now();
                // Jam pasir diputar sekali lagi dengan aturan yang sudah dipilih
                // owner, bukan konstanta baru. Gunanya menahan `orders:expire`
                // supaya tak menghanguskan pesanan yang uangnya sudah masuk
                // sementara kasir belum sempat memeriksa notifikasi mutasinya.
                $order->expires_at = now()->addMinutes((int) $setting->order_expiry_minutes);
                $order->save();
            }

            return $order;
        });

        return response()->json(['data' => $this->present($order->load('items'))]);
    }

    /**
     * Bentuk publik order: cukup untuk struk & polling, TANPA confirmed_by /
     * tenant_id / outlet_id / payment_method internal.
     *
     * @return array<string, mixed>
     */
    /**
     * Alamat gambar QRIS outlet, atau null kalau QR tak boleh ditampilkan.
     *
     * Penjaga status ada DI SINI, bukan di layar pelanggan, dan itu disengaja:
     * kalau frontend yang memutuskan, satu kondisi yang terlewat saat menata
     * ulang komponen langsung berubah jadi masalah uang. Server tak pernah
     * mengirim apa yang tak boleh ditampilkan, jadi UI tak punya kesempatan
     * salah.
     *
     * Dua kerusakan yang dicegahnya:
     * - status PAID masih memajang QR -> pelanggan membayar untuk kedua kalinya.
     * - status EXPIRED masih memajang QR -> uang masuk untuk pesanan yang sudah
     *   hangus, dan kafe yang menanggung ributnya.
     *
     * Efek sampingnya kebetulan bagus: query setting cuma jalan untuk order
     * yang memang sedang menunggu bayar, bukan pada tiap polling status yang
     * sudah final.
     */
    private function qrisUntuk(Order $order): ?string
    {
        if ($order->status !== OrderStatus::Pending) {
            return null;
        }

        // Pelanggan yang menyatakan akan membayar tunai tak butuh QR. Menampilkannya
        // tetap bukan sekadar berisik: ia mengundang orang memindai lalu membayar
        // lagi di kasir — dua kali bayar untuk satu pesanan.
        if ($order->inginTunai()) {
            return null;
        }

        return OrderSetting::query()
            ->where('outlet_id', $order->outlet_id)
            ->value('qris_image_url');
    }

    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'gross_subtotal' => $order->gross_subtotal,
            'discount_total' => $order->discount_total,
            'subtotal' => $order->subtotal,
            'service_charge' => $order->service_charge,
            'tax' => $order->tax,
            'grand_total' => $order->grand_total,
            'promotion' => $order->promotion_snapshot,
            'expires_at' => $order->expires_at,
            // Tiga titik garis kemajuan di layar pelanggan. Yang dikirim JAMnya,
            // bukan "sudah/belum": layar menuliskannya di bawah tiap titik, dan
            // null sudah cukup berarti "belum terjadi".
            //
            // `confirmed_at` dikirim sebagai `paid_at` — kosakata internal
            // ("dikonfirmasi kasir") tak perlu bocor ke pelanggan, yang
            // dipedulikannya cuma uangnya sudah diterima.
            'created_at' => $order->created_at,
            'paid_at' => $order->confirmed_at,
            'ready_at' => $order->ready_at,
            // Dibungkus objek, bukan field lepas di akar: instruksi pembayaran
            // masih akan tumbuh (penanda "pelanggan mengaku sudah bayar", cara
            // bayar selain QRIS), dan menambah kunci ke dalam objek yang sudah
            // ada jauh lebih murah daripada mengubah bentuk respons yang sudah
            // dipakai app pelanggan.
            'payment' => [
                'qris_image_url' => $this->qrisUntuk($order),
                // Dikembalikan supaya layar bisa menjelaskan APA yang sedang
                // ditunggu ("bayar di kasir" vs "pindai QR"), bukan cuma diam
                // saat QR-nya sengaja tak ada.
                'preference' => $order->payment_preference,
                // Jam klaim, bukan sekadar sudah/belum: layar menuliskannya
                // kembali ("dilaporkan 14:32") supaya pelanggan yang membuka
                // ulang halamannya tahu laporannya memang tercatat — tanpa itu
                // dia menekan tombol yang sama untuk kedua kalinya.
                'claimed_at' => $order->customer_claimed_paid_at,
            ],
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
