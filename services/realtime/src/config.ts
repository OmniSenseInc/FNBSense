import 'dotenv/config';

// Baca env → opsi koneksi (objek, bukan URL: menghindari pusing encoding vhost "/").
const env = (key: string, fallback = ''): string => process.env[key] ?? fallback;

export const connection = {
  hostname: env('RABBITMQ_HOST', '127.0.0.1'),
  port: Number(env('RABBITMQ_PORT', '5672')),
  username: env('RABBITMQ_USER', 'fnbsense'),
  password: env('RABBITMQ_PASSWORD'),
  vhost: env('RABBITMQ_VHOST', '/'),
};

// Nama topologi — CERMIN dari services/ordering/config/rabbitmq.php.
// Kalau salah satu berubah, dua-duanya harus ikut (kontrak lintas-bahasa).
export const topology = {
  exchange: 'fnbsense.events',        // topic, durable — di-assert relay
  dlx: 'fnbsense.events.dlx',         // tujuan dead-letter queue ini
  queue: 'realtime.orders',           // queue milik service ini
  routingKey: 'order.paid',           // event yang diminati (F3b: "order.#")
};

// Maks pesan belum-ACK yang boleh dipegang sekaligus (jangan sedot semua).
export const PREFETCH = 10;
