import amqp from 'amqplib';
import type { Channel, ConsumeMessage } from 'amqplib';
import { connection, topology, PREFETCH } from './config.js';

/**
 * Service Realtime FNBSense — consumer bukti F3a.
 *
 * Tugas SEKARANG: buktikan pipa relay→broker→consumer hidup. Terima event
 * `order.paid`, buang duplikat (idempotensi), log, ACK. BELUM WebSocket, BELUM
 * verifikasi JWT — itu F3b. Menjalankan ini juga yang membuat queue
 * `realtime.orders` eksis & ter-bind, syarat agar relay tak kehilangan pesan.
 */

// Dedup in-memory: at-least-once bisa mengirim ulang event yang sama; event_id
// yang sudah diproses dibuang. CATATAN: hilang saat restart → F3b naikkan ke Redis.
const processedEventIds = new Set<string>();

// Bentuk minimal amplop yang kita percayai sebelum memproses.
interface EventEnvelope {
  event_id: string;
  event_type: string;
  tenant_id?: string;
  outlet_id?: string;
  payload?: {
    order_id?: string;
    totals?: { grand_total?: number };
  };
}

async function start(): Promise<void> {
  const conn = await amqp.connect(connection);
  const channel = await conn.createChannel();

  // Assert hanya yang service ini butuh. Exchange di-assert idempoten (relay pun
  // meng-assert). Queue ini menunjuk DLX lewat x-dead-letter-exchange.
  await channel.assertExchange(topology.exchange, 'topic', { durable: true });
  await channel.assertQueue(topology.queue, {
    durable: true,
    arguments: { 'x-dead-letter-exchange': topology.dlx },
  });
  await channel.bindQueue(topology.queue, topology.exchange, topology.routingKey);
  await channel.prefetch(PREFETCH);

  console.log(
    `[realtime] siap — konsumsi queue "${topology.queue}" (routing key "${topology.routingKey}"). Menunggu event…`,
  );

  await channel.consume(topology.queue, (msg) => {
    if (msg !== null) {
      handleMessage(channel, msg);
    }
  });

  installShutdown(conn, channel);

  // Kalau koneksi putus, keluar dengan kode error → biar Supervisor/pm2 restart.
  conn.on('close', () => {
    console.error('[realtime] koneksi broker tertutup — keluar untuk di-restart.');
    process.exit(1);
  });
  conn.on('error', (err: Error) => console.error('[realtime] koneksi error:', err.message));
}

function handleMessage(channel: Channel, msg: ConsumeMessage): void {
  let event: EventEnvelope;
  try {
    event = JSON.parse(msg.content.toString()) as EventEnvelope;
  } catch {
    console.error('[realtime] payload bukan JSON valid → DLQ');
    channel.nack(msg, false, false); // requeue=false → dead-letter
    return;
  }

  // Validasi bentuk: amplop rusak jangan bikin crash, buang ke DLQ.
  if (!event.event_id || !event.event_type) {
    console.error('[realtime] amplop tak valid (event_id/event_type hilang) → DLQ');
    channel.nack(msg, false, false);
    return;
  }

  // Idempotensi: duplikat cukup di-ACK & abaikan.
  if (processedEventIds.has(event.event_id)) {
    console.log(`[realtime] duplikat diabaikan (event_id=${event.event_id})`);
    channel.ack(msg);
    return;
  }

  const orderId = event.payload?.order_id ?? '?';
  const total = event.payload?.totals?.grand_total ?? '?';
  console.log(
    `[realtime] ${event.event_type} diterima — order=${orderId} total=Rp${total} (event_id=${event.event_id})`,
  );

  processedEventIds.add(event.event_id);
  channel.ack(msg);
}

function installShutdown(conn: amqp.ChannelModel, channel: Channel): void {
  const shutdown = async (): Promise<void> => {
    console.log('\n[realtime] shutdown…');
    try {
      await channel.close();
      await conn.close();
    } catch {
      // koneksi mungkin sudah tertutup — abaikan
    }
    process.exit(0);
  };

  process.on('SIGINT', shutdown);
  process.on('SIGTERM', shutdown);
}

start().catch((err: unknown) => {
  console.error('[realtime] gagal start:', err);
  process.exit(1);
});
