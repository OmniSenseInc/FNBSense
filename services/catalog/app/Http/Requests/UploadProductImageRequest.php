<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unggahan foto produk oleh owner.
 *
 * Otorisasi ditangani middleware 'jwt' + 'role:owner' di route -> authorize() true.
 *
 * Aturan di sini adalah SARINGAN PERTAMA, bukan satu-satunya: controller
 * membongkar lalu menulis ulang gambarnya, sehingga berkas yang mendarat di
 * disk tak pernah berupa kiriman apa adanya.
 */
class UploadProductImageRequest extends FormRequest
{
    /** Batas ukuran berkas dalam kilobyte. Foto menu 3MB sudah sangat lega. */
    public const MAX_KILOBYTES = 3072;

    /**
     * Batas dimensi. Ukuran berkas SAJA tidak cukup: PNG kecil bisa memuai jadi
     * ratusan megabyte di memori saat dibongkar (decompression bomb), dan yang
     * mati bukan cuma request itu — seluruh proses PHP-nya ikut.
     */
    public const MAX_PIXELS = 3000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                // 'mimes' memeriksa ISI berkas lewat finfo, bukan ekstensi nama
                // maupun Content-Type — dua-duanya ditentukan pengunggah, jadi
                // dua-duanya bisa berbohong.
                //
                // SVG SENGAJA TIDAK ADA: dokumen XML yang boleh memuat <script>,
                // dan berkas ini berakhir di layar HP pelanggan yang sedang
                // memesan. Daftar-IZIN, bukan daftar-larangan.
                'mimes:png,jpg,jpeg',
                'dimensions:max_width='.self::MAX_PIXELS.',max_height='.self::MAX_PIXELS,
                'max:'.self::MAX_KILOBYTES,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Pilih foto produk yang mau diunggah.',
            'image.mimes' => 'Foto produk harus berformat PNG atau JPG.',
            'image.dimensions' => 'Ukuran foto maksimal '.self::MAX_PIXELS.'×'.self::MAX_PIXELS.' piksel.',
            'image.max' => 'Ukuran berkas maksimal '.(self::MAX_KILOBYTES / 1024).' MB.',
        ];
    }
}
