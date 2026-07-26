import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync } from 'node:crypto';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import jwt from 'jsonwebtoken';
import { WebSocket } from 'ws';

/**
 * Test server WebSocket KDS (F3b): auth handshake + isolasi broadcast per outlet.
 *
 * Sengaja TANPA broker — KdsServer memang tak menyentuh RabbitMQ; consumer yang
 * memanggilnya. Memisahkan keduanya bikin test ini deterministik.
 *
 * Assertion isolasi bertumpu pada NILAI BALIK broadcastToOutlet (jumlah klien
 * terkirim), bukan pada "menunggu pesan yang tak kunjung datang". Menunggu
 * ketiadaan pesan butuh timer, dan timer di service ini pernah menghasilkan
 * false-negative saat proses uji ter-suspend.
 */

const rsa = generateKeyPairSync('rsa', {
  modulusLength: 2048,
  publicKeyEncoding: { type: 'spki', format: 'pem' },
  privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
});

const dir = mkdtempSync(join(tmpdir(), 'fnbsense-realtime-kds-'));
const keyPath = join(dir, 'jwt-public.pem');
writeFileSync(keyPath, rsa.publicKey);
process.env.JWT_PUBLIC_KEY_PATH = keyPath;

const PORT = 18771;
const { KdsServer } = await import('./kds-server.js');

const server = new KdsServer(PORT);
await server.ready;
after(() => server.close());

/**
 * Outlet unik per test — sengaja BUKAN konstanta bersama.
 *
 * Socket dari test sebelumnya belum tentu sudah dilepas server saat test
 * berikutnya mulai (log sempat menunjukkan "total outlet ini: 2"). Kalau outlet
 * dipakai ulang, hitungan broadcast bisa terkontaminasi sisa koneksi dan test
 * jadi flaky di mesin yang lebih lambat — bukan karena kodenya salah.
 */
let urutan = 0;
const outletBaru = (): string => `outlet-${++urutan}`;

const token = (outletId: string): string =>
  jwt.sign(
    { sub: 'u-1', tenant_id: 't-1', outlet_id: outletId, role: 'cashier' },
    rsa.privateKey,
    { algorithm: 'RS256', expiresIn: 300 },
  );

const url = (query = ''): string => `ws://127.0.0.1:${PORT}/${query}`;

/** Sambung DAN tunggu sapaan "connected" — memastikan server sudah mendaftarkan klien. */
async function connectTerdaftar(outletId: string): Promise<WebSocket> {
  const socket = new WebSocket(url(`?token=${token(outletId)}`));
  const sapaan = await nextMessage(socket);
  assert.equal(sapaan.type, 'connected');
  assert.equal(sapaan.outlet_id, outletId);
  return socket;
}

function nextMessage(socket: WebSocket): Promise<any> {
  return new Promise((resolve, reject) => {
    socket.once('message', (data) => resolve(JSON.parse(String(data))));
    socket.once('error', reject);
  });
}

function closeCode(socket: WebSocket): Promise<number> {
  return new Promise((resolve) => socket.once('close', (code: number) => resolve(code)));
}

/** Polling berbatas — bukan sleep tetap; berhenti begitu nilainya sesuai. */
async function sampai(fn: () => number, harapan: number, batasMs = 2000): Promise<number> {
  const deadline = Date.now() + batasMs;
  let nilai = fn();
  while (nilai !== harapan && Date.now() < deadline) {
    await new Promise((r) => setTimeout(r, 20));
    nilai = fn();
  }
  return nilai;
}

test('koneksi tanpa token ditolak dengan close 4401', async () => {
  assert.equal(await closeCode(new WebSocket(url())), 4401);
});

test('koneksi dengan token sampah ditolak dengan close 4401', async () => {
  assert.equal(await closeCode(new WebSocket(url('?token=bukan-token'))), 4401);
});

test('koneksi dengan token sah disapa dan didaftarkan ke outletnya', async () => {
  const socket = await connectTerdaftar(outletBaru());
  socket.close();
});

test('broadcast hanya sampai ke outlet yang cocok', async () => {
  const outletA = outletBaru();
  const outletB = outletBaru();
  const a = await connectTerdaftar(outletA);
  const b = await connectTerdaftar(outletB);

  const diterima = nextMessage(a);
  // Kalau isolasi bocor, angka ini 2 — bukan 1.
  assert.equal(server.broadcastToOutlet(outletA, { order_id: 'ORD-1' }), 1);
  assert.equal(server.broadcastToOutlet(outletB, { order_id: 'ORD-2' }), 1);

  const pesan = await diterima;
  assert.equal(pesan.type, 'order.paid');
  assert.equal(pesan.event.order_id, 'ORD-1');

  a.close();
  b.close();
});

test('outlet tanpa klien tidak menghitung siapa pun', () => {
  assert.equal(server.broadcastToOutlet('outlet-tak-ada', { order_id: 'ORD-X' }), 0);
});

test('klien yang menutup koneksi dilepas dari registry', async () => {
  const outlet = outletBaru();
  const socket = await connectTerdaftar(outlet);
  assert.equal(server.broadcastToOutlet(outlet, { order_id: 'ORD-3' }), 1);

  socket.close();

  assert.equal(await sampai(() => server.broadcastToOutlet(outlet, {}), 0), 0);
});