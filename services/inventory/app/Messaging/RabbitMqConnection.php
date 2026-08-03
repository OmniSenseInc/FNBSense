<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Satu-satunya tempat membuka koneksi ke broker (F4b). Kredensial hanya dibaca
 * dari config('rabbitmq.connection') — tak ada string koneksi terserak di kode.
 */
class RabbitMqConnection
{
    /**
     * Detak jantung ke broker. Tanpa ini, broker yang tak mendengar apa pun
     * dari kita selama antrean sepi memutus koneksinya sendiri.
     */
    private const HEARTBEAT_DETIK = 60;

    /**
     * Batas diam di socket. WAJIB minimal dua kali heartbeat.
     *
     * Bawaan php-amqplib untuk nilai ini 3 DETIK, dan itu menjatuhkan consumer
     * setiap antrean sepi tiga detik. Di jalur potong stok akibatnya lebih
     * tajam daripada di notifikasi: daemon yang mati diam-diam membuat
     * `order.paid` menumpuk di queue, dan stok berhenti terpotong tanpa satu
     * pun tanda di layar mana pun.
     */
    private const DIAM_MAKS_DETIK = 130.0;

    private const SAMBUNG_MAKS_DETIK = 10.0;

    public static function open(): AMQPStreamConnection
    {
        $c = config('rabbitmq.connection');

        return new AMQPStreamConnection(
            $c['host'],
            $c['port'],
            $c['user'],
            $c['password'],
            $c['vhost'],
            insist: false,
            login_method: 'AMQPLAIN',
            login_response: null,
            locale: 'en_US',
            connection_timeout: self::SAMBUNG_MAKS_DETIK,
            read_write_timeout: self::DIAM_MAKS_DETIK,
            context: null,
            keepalive: true,
            heartbeat: self::HEARTBEAT_DETIK,
        );
    }
}
