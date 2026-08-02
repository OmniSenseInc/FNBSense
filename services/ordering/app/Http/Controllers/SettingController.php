<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingRequest;
use App\Http\Requests\UploadQrisRequest;
use App\Models\OrderSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tarif transaksi outlet — owner saja.
 *
 * Outlet yang belum pernah dikonfigurasi TIDAK punya baris di DB. show() sengaja
 * mengembalikan default bertarif 0 tanpa menulis apa pun: membaca setting tidak
 * boleh diam-diam membuat data.
 */
class SettingController extends Controller
{
    /** Disk berkas publik — gambar QRIS harus terbaca pelanggan tanpa login. */
    private const DISK = 'public';

    /** Awalan URL hasil `php artisan storage:link`. */
    private const PREFIX = '/storage/';


    public function show(Request $request): JsonResponse
    {
        $setting = $this->find($request)
            ?? OrderSetting::defaultsFor($this->tenantId($request), $this->outletId($request));

        // Batas ikut dikirim, tidak dibiarkan disalin klien.
        //
        // Layar setelan owner butuh angka-angka ini untuk memandu sebelum
        // menekan Simpan. Kalau ia menyalinnya sendiri, ada dua sumber
        // kebenaran yang bisa berselisih diam-diam: konstanta di sini berubah,
        // layar tetap memandu ke angka lama, dan tak ada satu pun test yang
        // merah. Dikirim dari sini, otoritasnya tetap tunggal — dan yang
        // menolak tetap validasi server, bukan formnya.
        return $this->respond($setting);
    }

    /**
     * Satu bentuk jawaban untuk ketiga endpoint setelan.
     *
     * Kalau hanya show() yang membawa `limits`, layar owner kehilangan batasnya
     * tepat setelah menyimpan — dan sisa sesi itu ia memandu dengan angka yang
     * sudah tak ada.
     */
    private function respond(OrderSetting $setting): JsonResponse
    {
        return response()->json(['data' => $setting->toArray() + ['limits' => self::limits()]]);
    }

    /**
     * Rentang yang boleh diisi owner, apa adanya dari sumbernya masing-masing.
     *
     * Sengaja menunjuk konstanta, bukan menuliskan ulang angkanya: mengubah
     * batas di satu tempat harus langsung terlihat di layar owner.
     *
     * @return array<string, int>
     */
    private static function limits(): array
    {
        return [
            'tax_percent_max' => OrderSetting::MAX_TAX_PERCENT,
            'service_charge_percent_max' => OrderSetting::MAX_SERVICE_CHARGE_PERCENT,
            // Satu-satunya angka yang ditulis di sini, bukan diambil dari
            // konstanta: 'min:1' di UpdateSettingRequest memang literal, dan
            // membuat konstanta untuk angka satu cuma menambah tempat melihat.
            'order_expiry_minutes_min' => 1,
            'order_expiry_minutes_max' => OrderSetting::MAX_EXPIRY_MINUTES,
            'qris_max_kilobytes' => UploadQrisRequest::MAX_KILOBYTES,
            'qris_max_pixels' => UploadQrisRequest::MAX_PIXELS,
            'outlet_name_max' => OrderSetting::MAX_OUTLET_NAME_LENGTH,
            'outlet_address_max' => OrderSetting::MAX_OUTLET_ADDRESS_LENGTH,
            'outlet_phone_max' => OrderSetting::MAX_OUTLET_PHONE_LENGTH,
        ];
    }

    public function update(UpdateSettingRequest $request): JsonResponse
    {
        $setting = $this->find($request);

        if ($setting !== null) {
            return $this->respond($this->applyTo($setting, $request));
        }

        // Baris lahir saat owner pertama kali menyimpan tarif. Ini titik balapan:
        // dua request bersamaan (double-klik Save, retry klien) sama-sama melihat
        // "belum ada baris" lalu sama-sama INSERT. Yang kalah ditolak unique
        // constraint outlet_id. Cek-lalu-tulis TIDAK bisa menutup ini — DB yang
        // memutuskan siapa menang, jadi kekalahan itu ditangkap dan diselesaikan
        // sebagai update, bukan dibiarkan bocor jadi 500 ke owner.
        $baru = OrderSetting::defaultsFor($this->tenantId($request), $this->outletId($request));

        try {
            return $this->respond($this->applyTo($baru, $request));
        } catch (UniqueConstraintViolationException) {
            $pemenang = $this->find($request);

            return $this->respond($this->applyTo($pemenang, $request));
        }
    }

    /**
     * Owner mengunggah gambar QRIS outlet ini.
     *
     * Berkas TIDAK pernah disimpan apa adanya. Ia dibongkar jadi piksel lalu
     * ditulis ulang sebagai PNG baru, dan itulah pertahanan utamanya: apa pun
     * yang diselipkan di metadata atau di ekor berkas tidak ikut terbawa,
     * karena yang tersimpan adalah berkas yang KITA buat, bukan yang dikirim.
     * Daftar-izin MIME di UploadQrisRequest menyaring lebih dulu; ini jaring
     * kedua untuk hal-hal yang lolos dari pemeriksaan tipe.
     */
    public function uploadQris(UploadQrisRequest $request): JsonResponse
    {
        /** @var UploadedFile $berkas */
        $berkas = $request->file('qris');
        $bersih = $this->reencode($berkas);

        if ($bersih === null) {
            return response()->json(['message' => 'Berkas tidak bisa dibaca sebagai gambar.'], 422);
        }

        // Nama dibuat server. Nama asli dari pengunggah tak pernah menyentuh
        // sistem berkas — di situlah path traversal biasanya masuk.
        $jalur = 'qris/'.Str::uuid().'.png';
        Storage::disk(self::DISK)->put($jalur, $bersih);

        $setting = $this->find($request)
            ?? OrderSetting::defaultsFor($this->tenantId($request), $this->outletId($request));

        $lama = $setting->qris_image_url;
        $setting->qris_image_url = self::PREFIX.$jalur;
        $setting->save();

        // Baru dihapus SETELAH yang baru tersimpan: kalau urutannya dibalik dan
        // penyimpanan gagal, outlet kehilangan QRIS-nya tanpa punya gantinya.
        $this->hapusBerkasLama($lama);

        return $this->respond($setting);
    }

    /**
     * Gambar apa pun -> PNG berlatar putih. Null kalau isinya bukan gambar.
     */
    private function reencode(UploadedFile $berkas): ?string
    {
        $isi = @file_get_contents($berkas->getRealPath());
        $sumber = $isi === false ? false : @imagecreatefromstring($isi);

        if ($sumber === false) {
            return null;
        }

        $lebar = imagesx($sumber);
        $tinggi = imagesy($sumber);

        // Latar putih dipaksa, bukan dipertahankan transparan: QRIS PNG
        // beralpha menjadi hitam-di-atas-hitam saat diratakan, dan pemindai
        // butuh kontras gelap-di-terang untuk bisa membacanya sama sekali.
        $kanvas = imagecreatetruecolor($lebar, $tinggi);
        imagefilledrectangle($kanvas, 0, 0, $lebar, $tinggi, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopy($kanvas, $sumber, 0, 0, 0, 0, $lebar, $tinggi);

        ob_start();
        imagepng($kanvas);
        $hasil = (string) ob_get_clean();

        imagedestroy($sumber);
        imagedestroy($kanvas);

        return $hasil;
    }

    /**
     * Hapus berkas QRIS lama, kalau memang berkas yang kita simpan sendiri.
     *
     * Kolom yang sama juga bisa memuat URL eksternal (diisi lewat PUT settings),
     * dan nilai yang pernah disentuh manusia tak boleh berubah jadi perintah
     * hapus berkas. basename() membuang komponen jalur apa pun, jadi "../.."
     * tak punya arti di sini.
     */
    private function hapusBerkasLama(?string $lama): void
    {
        if ($lama === null || ! str_starts_with($lama, self::PREFIX.'qris/')) {
            return;
        }

        Storage::disk(self::DISK)->delete('qris/'.basename($lama));
    }

    private function applyTo(OrderSetting $setting, UpdateSettingRequest $request): OrderSetting
    {
        $setting->fill($request->validated());
        $setting->save();

        return $setting;
    }

    /**
     * Setting outlet ini, atau null kalau belum pernah dikonfigurasi.
     *
     * Di-scope tenant_id DAN outlet_id: outlet_id memang unique global, tapi
     * menyaring tenant juga bikin baris milik tenant lain mustahil tersentuh
     * walau outlet_id-nya entah bagaimana ketebak.
     */
    private function find(Request $request): ?OrderSetting
    {
        return OrderSetting::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            ->first();
    }
}
