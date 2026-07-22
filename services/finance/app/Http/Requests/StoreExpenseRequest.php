<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Catat pengeluaran. amount = rupiah integer > 0 (nol/negatif ditolak; DB juga
 * mengunci lewat CHECK). tenant/outlet/pencatat dari JWT, bukan body (anti-IDOR).
 * spent_at opsional → default now() di controller; TAK boleh masa depan (mencegah
 * pengeluaran hantu di luar window rekonsiliasi). Kategori dikunci ke set tertutup
 * biar rincian by_category laporan tak pecah gara-gara typo/beda kapital.
 */
class StoreExpenseRequest extends FormRequest
{
    /** Kategori pengeluaran yang sah (kunci breakdown laporan). */
    public const CATEGORIES = ['bahan', 'operasional', 'gaji', 'lain'];

    public function authorize(): bool
    {
        return true; // otorisasi via middleware jwt+role
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
