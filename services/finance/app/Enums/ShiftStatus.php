<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status shift kasir. open → closed adalah transisi satu-arah terminal:
 * shift yang sudah ditutup tak bisa dibuka lagi (laporan & selisih kas final).
 */
enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
