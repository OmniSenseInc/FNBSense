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
        ];
    }
}
