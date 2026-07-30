<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Owner mengunggah gambar QRIS outlet.
 *
 * Fokus gigi: berkas yang mendarat di disk tak pernah berupa kiriman apa
 * adanya, dan yang boleh mengunggah cuma owner. Dua kerusakan yang dijaga di
 * sini bukan soal kerapian — SVG ber-<script> yang tampil di HP pelanggan
 * saat membayar, dan berkas lama yang menumpuk tiap owner ganti rekening.
 */
class QrisUploadTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();

        Storage::fake('public');
    }

    private function unggah(UploadedFile $berkas, string $role = 'owner')
    {
        return $this->post(
            '/api/settings/qris',
            ['qris' => $berkas],
            $this->authHeaders($this->tenantId, $this->outletId, $role),
        );
    }

    public function test_owner_mengunggah_qris_dan_berkasnya_tersimpan(): void
    {
        $res = $this->unggah(UploadedFile::fake()->image('qris.png', 300, 300));

        $res->assertOk();

        $url = $res->json('data.qris_image_url');
        // Bentuk alamatnya ikut diuji, bukan cuma "ada isinya": layar pelanggan
        // menyusun alamat lengkap dari nilai ini, jadi awalannya bagian kontrak.
        $this->assertStringStartsWith('/storage/qris/', $url);
        Storage::disk('public')->assertExists('qris/'.basename($url));
    }

    public function test_svg_ditolak_walau_berisi_markup_gambar_yang_sah(): void
    {
        // Inilah alasan daftar-izin MIME ada. SVG adalah XML, dan XML boleh
        // memuat <script> yang berjalan di HP orang yang sedang membayar.
        $jahat = UploadedFile::fake()->createWithContent(
            'qris.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $res = $this->unggah($jahat);

        $res->assertStatus(422);
        // Status 422 saja TIDAK cukup, dan ini bukan kerewelan: mutasi
        // membuktikan SVG tetap ditolak walau daftar-izin MIME dicabut —
        // rule 'dimensions' kebetulan menolaknya lebih dulu karena getimagesize
        // gagal membaca XML. Test yang berhenti di status akan tetap hijau
        // sementara pertahanan sesungguhnya sudah hilang. Jadi yang diperiksa
        // adalah ALASAN penolakannya.
        $this->assertStringContainsString('PNG atau JPG', (string) $res->json('errors.qris.0'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_berkas_lama_dihapus_saat_qris_diganti(): void
    {
        $lama = $this->unggah(UploadedFile::fake()->image('lama.png', 200, 200))
            ->json('data.qris_image_url');

        $this->unggah(UploadedFile::fake()->image('baru.png', 200, 200))->assertOk();

        // Tanpa ini, tiap kali owner ganti rekening satu berkas yatim tertinggal
        // selamanya — pelan tapi tak pernah berhenti tumbuh.
        Storage::disk('public')->assertMissing('qris/'.basename($lama));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_kasir_tidak_boleh_mengunggah(): void
    {
        $this->unggah(UploadedFile::fake()->image('q.png', 200, 200), 'cashier')
            ->assertForbidden();
    }

    public function test_gambar_melebihi_batas_dimensi_ditolak(): void
    {
        // Ukuran berkas kecil tapi piksel raksasa = bom dekompresi; yang mati
        // bukan cuma request ini, tapi proses PHP-nya.
        $this->unggah(UploadedFile::fake()->image('raksasa.png', 2100, 2100))
            ->assertStatus(422);
    }
}
