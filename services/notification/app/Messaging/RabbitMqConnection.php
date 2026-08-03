<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMqConnection
{
    /**
     * Detak jantung ke broker. Tanpa ini, broker yang tak mendengar apa pun
     * dari kita selama antrean sepi akan memutus koneksinya sendiri — dan
     * consumer baru menyadarinya saat pesan berikutnya sudah telanjur hilang.
     */
    private const HEARTBEAT_DETIK = 60;

    /**
     * Batas diam di socket. WAJIB minimal dua kali heartbeat: kalau lebih
     * pendek, socket-nya menyerah sebelum detak berikutnya sempat dikirim.
     *
     * Bawaan php-amqplib untuk nilai ini adalah 3 DETIK, dan itulah yang dulu
     * menjatuhkan consumer setiap kali antrean sepi tiga detik — keadaan yang
     * di kafe sungguhan justru normal, bukan pengecualian.
     */
    private const DIAM_MAKS_DETIK = 130.0;

    private const SAMBUNG_MAKS_DETIK = 10.0;

    public static function open(): AMQPStreamConnection
    {
        $connection = config('rabbitmq.connection');

        return new AMQPStreamConnection(
            $connection['host'], $connection['port'], $connection['user'],
            $connection['password'], $connection['vhost'],
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
