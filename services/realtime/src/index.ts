import amqp from 'amqplib';
import type { Channel, ConsumeMessage } from 'amqplib';
import { connection, topology, PREFETCH, ws as wsConfig } from './config.js';
import { KdsServer } from './kds-server.js';

/**
 * Service Realtime FNBSense (F3b).
 *
 * Consume event `order.paid` dari RabbitMQ → buang duplikat (idempotensi) →
 * BROADCAST ke klien KDS (WebSocket) di outlet yang cocok. F3a membuktikan pipa
 * hidup; F3b menaruh layar dapur di ujungnya.
 */

// Dedup in-memory: at-least-once bisa mengirim ulang. CATATAN: hilang saat
// restart → utang teknis, naikkan ke Redis kalau butuh tahan-restart.
const processedEventIds = new Set<string>();

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
  const kds = new KdsServer(wsConfig.port);

  const conn = await amqp.connect(connection);
  const channel = await conn.createChannel();

  await channel.assertExchange(topology.exchange, 'topic', { durable: true });
  await channel.assertQueue(topology.queue, {
    durable: true,
    arguments: { 'x-dead-letter-exchange': topology.dlx },
  });
  await channel.bindQueue(topology.queue, topology.exchange, topology.routingKey);
  await channel.prefetch(PREFETCH);

  console.log(
    `[realtime] siap — konsumsi queue "${topology.queue}" (routing key "${topology.routingKey}").`,
  );

  await channel.consume(topology.queue, (msg) => {
    if (msg !== null) {
      handleMessage(channel, msg, kds);
    }
  });

  installShutdown(conn, channel, kds);

  conn.on('close', () => {
    console.error('[realtime] koneksi broker tertutup — keluar untuk di-restart.');
    process.exit(1);
  });
  conn.on('error', (err: Error) => console.error('[realtime] koneksi error:', err.message));
}

function handleMessage(channel: Channel, msg: ConsumeMessage, kds: KdsServer): void {
  let event: EventEnvelope;
  try {
    event = JSON.parse(msg.content.toString()) as EventEnvelope;
  } catch {
    console.error('[realtime] payload bukan JSON valid → DLQ');
    channel.nack(msg, false, false); // requeue=false → dead-letter
    return;
  }

  if (!event.event_id || !event.event_type || !event.outlet_id) {
    console.error('[realtime] amplop tak valid (event_id/event_type/outlet_id hilang) → DLQ');
    channel.nack(msg, false, false);
    return;
  }

  // Idempotensi: duplikat cukup di-ACK & abaikan (jangan broadcast dua kali).
  if (processedEventIds.has(event.event_id)) {
    console.log(`[realtime] duplikat diabaikan (event_id=${event.event_id})`);
    channel.ack(msg);
    return;
  }

  const orderId = event.payload?.order_id ?? '?';
  const total = event.payload?.totals?.grand_total ?? '?';
  const delivered = kds.broadcastToOutlet(event.outlet_id, event);
  console.log(
    `[realtime] ${event.event_type} order=${orderId} total=Rp${total} → broadcast ke ${delivered} KDS ` +
      `(outlet=${event.outlet_id}, event_id=${event.event_id})`,
  );

  processedEventIds.add(event.event_id);
  channel.ack(msg);
}

function installShutdown(conn: amqp.ChannelModel, channel: Channel, kds: KdsServer): void {
  const shutdown = async (): Promise<void> => {
    console.log('\n[realtime] shutdown…');
    try {
      kds.close();
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
