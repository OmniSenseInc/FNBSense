<?php

namespace App\Enums;

/**
 * Jenis order. Dine-in terikat meja (table_id terisi); takeaway tanpa meja.
 */
enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
}
