import { WebSocketServer, WebSocket } from 'ws';
import type { IncomingMessage } from 'node:http';
import { verifyToken, type Claims } from './auth.js';

/**
 * Server WebSocket KDS (F3b). Klien dapur connect dengan JWT di query
 * (?token=<JWT>); server verifikasi saat handshake, tolak (close 4401) kalau
 * gagal. Koneksi dikelompokkan per outlet_id → broadcast HANYA ke outlet yang
 * cocok dengan event (isolasi; outlet_id UUID unik global = batas tenant).
 */
/** Ping keepalive tiap interval ini; klien yang lewat 1 siklus tanpa pong di-terminate. */
const HEARTBEAT_MS = 30_000;

export class KdsServer {
  private readonly server: WebSocketServer;
  private readonly clientsByOutlet = new Map<string, Set<WebSocket>>();
  /** Socket yang sudah balas pong sejak ping terakhir; false = dicurigai mati. */
  private readonly alive = new WeakMap<WebSocket, boolean>();
  private readonly heartbeat: NodeJS.Timeout;
  /**
   * Selesai saat server siap menerima koneksi.
   *
   * Ada demi test: tanpa ini test harus menebak delay, dan tebakan waktu di
   * service ini sudah pernah bikin false-negative (socket 1006 saat proses
   * uji ter-suspend). Produksi boleh mengabaikannya.
   */
  readonly ready: Promise<void>;

  constructor(port: number) {
    this.server = new WebSocketServer({ port });
    this.ready = new Promise((resolve, reject) => {
      this.server.once('listening', () => resolve());
      this.server.once('error', reject);
    });
    this.server.on('connection', (socket, req) => this.onConnection(socket, req));
    this.heartbeat = setInterval(() => this.pingAll(), HEARTBEAT_MS);
    console.log(`[kds] WebSocket listen di ws://localhost:${port} (auth: query ?token=<JWT>)`);
  }

  private onConnection(socket: WebSocket, req: IncomingMessage): void {
    let claims: Claims;
    try {
      const url = new URL(req.url ?? '', 'http://localhost');
      claims = verifyToken(url.searchParams.get('token') ?? '');
    } catch {
      socket.close(4401, 'unauthorized');
      return;
    }

    this.register(claims.outlet_id, socket);
    this.alive.set(socket, true);
    console.log(
      `[kds] klien tersambung outlet=${claims.outlet_id} role=${claims.role} ` +
        `(total outlet ini: ${this.clientsByOutlet.get(claims.outlet_id)?.size})`,
    );

    socket.on('pong', () => this.alive.set(socket, true));
    socket.on('close', () => this.unregister(claims.outlet_id, socket));
    socket.on('error', (err: Error) => console.error('[kds] socket error:', err.message));
    socket.send(JSON.stringify({ type: 'connected', outlet_id: claims.outlet_id }));
  }

  /** Terminate socket yang belum balas pong sejak siklus lalu; sisanya di-ping ulang. */
  private pingAll(): void {
    for (const clients of this.clientsByOutlet.values()) {
      for (const socket of clients) {
        if (this.alive.get(socket) === false) {
          socket.terminate(); // memicu 'close' → unregister
          continue;
        }
        this.alive.set(socket, false);
        socket.ping();
      }
    }
  }

  private register(outletId: string, socket: WebSocket): void {
    let set = this.clientsByOutlet.get(outletId);
    if (set === undefined) {
      set = new Set();
      this.clientsByOutlet.set(outletId, set);
    }
    set.add(socket);
  }

  private unregister(outletId: string, socket: WebSocket): void {
    const set = this.clientsByOutlet.get(outletId);
    set?.delete(socket);
    if (set !== undefined && set.size === 0) {
      this.clientsByOutlet.delete(outletId);
    }
  }

  /** Kirim event ke semua klien KDS di outlet itu. @return jumlah klien terkirim. */
  broadcastToOutlet(outletId: string, event: unknown): number {
    const clients = this.clientsByOutlet.get(outletId);
    if (clients === undefined || clients.size === 0) {
      return 0;
    }

    const data = JSON.stringify({ type: 'order.paid', event });
    let sent = 0;
    for (const socket of clients) {
      if (socket.readyState === WebSocket.OPEN) {
        socket.send(data);
        sent++;
      }
    }
    return sent;
  }

  close(): void {
    clearInterval(this.heartbeat);
    this.server.close();
  }
}
