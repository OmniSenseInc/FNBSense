<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar saat item order menyebut produk yang tidak ada di menu tenant
 * (tak dikenal, tak tersedia, atau milik tenant lain). Ditangkap di controller
 * dan diterjemahkan jadi 422 — ini pertahanan skrutini #3: mencegah memesan
 * produk yang bukan hak tenant ini atau yang tak dijual.
 */
class ProductNotOrderableException extends RuntimeException
{
}
