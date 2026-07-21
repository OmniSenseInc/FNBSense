<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * Keputusan consumer atas satu pesan — dipisah dari mekanik AMQP supaya inti
 * (OrderPaidConsumer) bisa diuji tanpa broker. Command menerjemahkan ini jadi
 * ack / nack sungguhan.
 */
enum ConsumeOutcome
{
    case Ack;       // selesai (sukses / duplikat / resep kosong) → basic_ack
    case Requeue;   // transient (Catalog down / DB error) → nack(requeue) → coba lagi
    case Dead;      // malformed / tak bisa diproses → nack(requeue=false) → DLQ
}
