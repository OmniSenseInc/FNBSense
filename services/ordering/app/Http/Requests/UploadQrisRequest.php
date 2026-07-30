<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unggahan gambar QRIS oleh owner.
 *
 * Otorisasi ditangani middleware 'jwt' + 'role:owner' di route -> authorize() true.
 *
 * Aturan di sini adalah SARINGAN PERTAMA, bukan satu-satunya: controller
 * membongkar lalu menulis ulang gambarnya, sehingga berkas yang mendarat di
 * disk tak pernah berupa kiriman apa adanya.
 */
class UploadQrisRequest extends FormRequest
{
    /** Batas ukuran berkas dalam kilobyte. QRIS 2MB sudah sangat lega. */
    public const MAX_KILOBYTES = 2048;

    /**
     * Batas dimensi. Ukuran berkas SAJA tidak cukup: PNG 40KB bisa memuai jadi
     * ratusan megabyte di memori saat dibongkar (decompression bomb), dan yang
     * mati bukan cuma request itu — seluruh proses PHP-nya ikut.
     */
    public const MAX_PIXELS = 2000;

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
            'qris' => [
                'required',
                'file',
                // 'mimes' memeriksa ISI berkas lewat finfo, bukan ekstensi nama
                // maupun Content-Type — dua-duanya ditentukan pengunggah, jadi
                // dua-duanya bisa berbohong.
                //
                // SVG SENGAJA TIDAK ADA di daftar ini. Dia dokumen XML yang boleh
                // memuat <script>, dan berkas ini berakhir di layar HP pelanggan
                // yang sedang membayar. Daftar-IZIN, bukan daftar-larangan:
                // format baru harus ditambahkan sadar, bukan lolos diam-diam.
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
            'qris.required' => 'Pilih gambar QRIS yang mau diunggah.',
            'qris.mimes' => 'Gambar QRIS harus berformat PNG atau JPG.',
            'qris.dimensions' => 'Ukuran gambar maksimal '.self::MAX_PIXELS.'×'.self::MAX_PIXELS.' piksel.',
            'qris.max' => 'Ukuran berkas maksimal '.(self::MAX_KILOBYTES / 1024).' MB.',
        ];
    }
}
