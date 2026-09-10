<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UploadProductImageRequest;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /** Disk berkas publik — foto produk harus terbaca pelanggan tanpa login. */
    private const DISK = 'public';

    /** Awalan URL hasil `php artisan storage:link`. */
    private const PREFIX = '/storage/';

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $products]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantId($request),
        ]);

        return response()->json(['data' => $product], 201);
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        $product->update($request->validated());

        return response()->json(['data' => $product]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        // Bersihkan resep & foto SEKALIGUS — tak ada anak yatim tertinggal
        // (resep tanpa produk, berkas foto tanpa produk).
        Recipe::where('product_id', $product->id)->delete();
        $this->hapusBerkasLama($product->image_url);

        $product->delete();

        return response()->json(['message' => 'Produk dihapus.']);
    }

    /**
     * Owner mengunggah foto produk.
     *
     * Sepola unggahan QRIS di Ordering: berkas tak pernah disimpan apa adanya,
     * ia dibongkar jadi piksel lalu ditulis ulang (JPEG) — metadata dan apa pun
     * yang diselipkan di ekor berkas tak ikut terbawa, karena yang tersimpan
     * adalah berkas yang KITA buat, bukan yang dikirim.
     */
    public function uploadImage(UploadProductImageRequest $request, string $id): JsonResponse
    {
        $product = Product::query()
            ->where('tenant_id', $this->tenantId($request))
            ->findOrFail($id);

        /** @var UploadedFile $berkas */
        $berkas = $request->file('image');
        $bersih = $this->reencode($berkas);

        if ($bersih === null) {
            return response()->json(['message' => 'Berkas tidak bisa dibaca sebagai gambar.'], 422);
        }

        // Nama dibuat server; nama asli pengunggah tak pernah menyentuh sistem
        // berkas — di situlah path traversal biasanya masuk.
        $jalur = 'products/'.Str::uuid().'.jpg';
        Storage::disk(self::DISK)->put($jalur, $bersih);

        $lama = $product->image_url;
        $product->image_url = self::PREFIX.$jalur;
        $product->save();

        // Hapus SETELAH yang baru tersimpan, supaya kegagalan menyimpan tak
        // meninggalkan produk tanpa foto.
        $this->hapusBerkasLama($lama);

        return response()->json(['data' => $product]);
    }

    /**
     * Gambar apa pun -> JPEG berlatar putih. Null kalau isinya bukan gambar.
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

        // Latar putih dipaksa, bukan dipertahankan transparan: JPEG tak punya
        // saluran alpha, dan PNG transparan yang diratakan jadi hitam pekat.
        $kanvas = imagecreatetruecolor($lebar, $tinggi);
        imagefilledrectangle($kanvas, 0, 0, $lebar, $tinggi, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopy($kanvas, $sumber, 0, 0, 0, 0, $lebar, $tinggi);

        ob_start();
        imagejpeg($kanvas, null, 82);
        $hasil = (string) ob_get_clean();

        imagedestroy($sumber);
        imagedestroy($kanvas);

        return $hasil;
    }

    /**
     * Hapus foto lama, kalau memang berkas yang kita simpan sendiri.
     *
     * Kolom yang sama bisa saja memuat URL eksternal (diisi lewat PUT), dan
     * nilai yang pernah disentuh manusia tak boleh berubah jadi perintah hapus
     * berkas. basename() membuang komponen jalur apa pun, jadi "../.." tak
     * punya arti di sini.
     */
    private function hapusBerkasLama(?string $lama): void
    {
        if ($lama === null || ! str_starts_with($lama, self::PREFIX.'products/')) {
            return;
        }

        Storage::disk(self::DISK)->delete('products/'.basename($lama));
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }
}
