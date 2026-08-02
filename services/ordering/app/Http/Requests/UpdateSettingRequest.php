<?php

namespace App\Http\Requests;

use App\Models\OrderSetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    /** Otorisasi ditangani middleware 'jwt' + 'role:owner' di route. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Batas persen dibaca dari konstanta model — sumber yang SAMA dengan CHECK
     * constraint di DB. Aturan di sini bukan pengaman utama (DB yang menegakkan),
     * melainkan penerjemah: mengubah penolakan jadi 422 yang bisa dibaca owner,
     * bukan 500 mentah dari MySQL.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:'.OrderSetting::MAX_TAX_PERCENT],
            'service_charge_percent' => ['sometimes', 'numeric', 'min:0', 'max:'.OrderSetting::MAX_SERVICE_CHARGE_PERCENT],
            'order_expiry_minutes' => ['sometimes', 'integer', 'min:1', 'max:'.OrderSetting::MAX_EXPIRY_MINUTES],
            // nullable disengaja: owner harus bisa MENCABUT QRIS-nya (kirim
            // null), bukan cuma menggantinya. Tanpa itu, QRIS rekening lama
            // menempel selamanya begitu sekali dipasang.
            //
            // 'url' bukan sekadar kerapian: alamat ini berakhir sebagai <img>
            // di layar pelanggan yang sedang membayar. Teks sembarangan di
            // sana bukan cuma gambar rusak — ia gambar rusak di layar yang
            // paling tidak boleh membuat orang ragu.
            'qris_image_url' => ['sometimes', 'nullable', 'url', 'max:'.OrderSetting::MAX_QRIS_URL_LENGTH],
            // nullable: kafe yang tak ingin alamat/telepon di struk harus bisa
            // mengosongkannya lagi, bukan cuma menggantinya.
            //
            // Sengaja TIDAK ada aturan format untuk telepon. Nomor kafe ditulis
            // orang dengan segala macam gaya (+62, spasi, tanda hubung, dua
            // nomor sekaligus), dan menolak yang tak sesuai pola cuma akan
            // menghalangi owner menulis nomornya sendiri dengan benar. Isinya
            // dicetak apa adanya, tak pernah dipanggil sistem.
            'outlet_name' => ['sometimes', 'nullable', 'string', 'max:'.OrderSetting::MAX_OUTLET_NAME_LENGTH],
            'outlet_address' => ['sometimes', 'nullable', 'string', 'max:'.OrderSetting::MAX_OUTLET_ADDRESS_LENGTH],
            'outlet_phone' => ['sometimes', 'nullable', 'string', 'max:'.OrderSetting::MAX_OUTLET_PHONE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_percent.max' => 'Tarif pajak maksimal '.OrderSetting::MAX_TAX_PERCENT.'%.',
            'service_charge_percent.max' => 'Service charge maksimal '.OrderSetting::MAX_SERVICE_CHARGE_PERCENT.'%.',
            'qris_image_url.url' => 'Alamat gambar QRIS harus berupa URL yang sah.',
            'qris_image_url.max' => 'Alamat gambar QRIS maksimal '.OrderSetting::MAX_QRIS_URL_LENGTH.' karakter.',
        ];
    }
}
