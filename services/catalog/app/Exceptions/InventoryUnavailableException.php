<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Inventory tak terhubung / membalas non-2xx / membalas data tak dikenali.
 *
 * Dilempar oleh InventoryBalanceClient, BUKAN ditelan di sana: pemanggilnya
 * yang tahu apa arti kegagalan itu baginya. Untuk `/api/menu` artinya menu
 * tetap tampil tanpa penanda habis (fail-open) — lihat MenuController.
 */
class InventoryUnavailableException extends RuntimeException {}
