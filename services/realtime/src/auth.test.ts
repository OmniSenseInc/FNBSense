import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync } from 'node:crypto';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import jwt from 'jsonwebtoken';

/**
 * Test verifikasi JWT KDS (F3b).
 *
 * Kunci dibuat di runtime dan ditulis ke direktori sementara — test TIDAK
 * pernah menyentuh kunci asli repo, jadi bisa jalan di CI/mesin bersih.
 */

const rsa = () =>
  generateKeyPairSync('rsa', {
    modulusLength: 2048,
    publicKeyEncoding: { type: 'spki', format: 'pem' },
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
  });

const asli = rsa();
const penyerang = rsa();

const dir = mkdtempSync(join(tmpdir(), 'fnbsense-realtime-auth-'));
const keyPath = join(dir, 'jwt-public.pem');
writeFileSync(keyPath, asli.publicKey);

// WAJIB diset SEBELUM import: config.ts membaca env saat modul dimuat.
process.env.JWT_PUBLIC_KEY_PATH = keyPath;

const { verifyToken } = await import('./auth.js');

const KLAIM = { sub: 'u-1', tenant_id: 't-1', outlet_id: 'o-1', role: 'cashier' };

const sign = (payload: object, key: string, opts: jwt.SignOptions = {}): string =>
  jwt.sign(payload, key, { algorithm: 'RS256', expiresIn: 300, ...opts });

test('token sah menghasilkan klaim lengkap', () => {
  const claims = verifyToken(sign(KLAIM, asli.privateKey));

  assert.equal(claims.sub, 'u-1');
  assert.equal(claims.tenant_id, 't-1');
  assert.equal(claims.outlet_id, 'o-1');
  assert.equal(claims.role, 'cashier');
});

test('token tanpa outlet_id ditolak', () => {
  // KDS bekerja pada SATU outlet konkret. Token owner yang belum terikat outlet
  // tak boleh didiamkan — kalau lolos, klien masuk registry ber-key "undefined"
  // dan broadcast per-outlet kehilangan artinya.
  const { outlet_id, ...tanpaOutlet } = KLAIM;

  assert.throws(() => verifyToken(sign(tanpaOutlet, asli.privateKey)));
});

test('token ditandatangani kunci lain ditolak', () => {
  assert.throws(() => verifyToken(sign(KLAIM, penyerang.privateKey)));
});

test('token HS256 yang memakai public key sebagai secret ditolak', () => {
  // Algorithm confusion: public key kita TERBUKA, jadi siapa pun bisa memakainya
  // sebagai secret HMAC.
  //
  // CATATAN JUJUR (dibuktikan lewat mutasi 2026-07-26): membuang allowlist
  // `algorithms` TIDAK membuat test ini merah — jsonwebtoken sendiri sudah
  // menolak algoritma HMAC begitu kuncinya berupa PEM publik. Jadi test ini
  // mengunci PERILAKU PUSTAKA, bukan pagar kita. Berguna sebagai jaring kalau
  // pustaka berubah, tapi yang mengunci allowlist kita adalah test berikutnya.
  const palsu = jwt.sign(KLAIM, asli.publicKey, { algorithm: 'HS256', expiresIn: 300 });

  assert.throws(() => verifyToken(palsu));
});

test('token RS512 dari kunci privat yang SAMA ditolak', () => {
  // Ini pagar `algorithms: ['RS256']` yang sesungguhnya. Tanpa allowlist,
  // jsonwebtoken menerima algoritma asimetris APA PUN yang cocok dengan
  // kuncinya — penyerang yang menguasai kunci privat (atau layanan hulu yang
  // salah konfigurasi) bisa menggeser algoritma tanpa kita sadari.
  // Buang allowlist -> test ini merah.
  const geser = jwt.sign(KLAIM, asli.privateKey, { algorithm: 'RS512', expiresIn: 300 });

  assert.throws(() => verifyToken(geser));
});

test('token kedaluwarsa ditolak', () => {
  assert.throws(() => verifyToken(sign(KLAIM, asli.privateKey, { expiresIn: -1 })));
});

test('token sampah ditolak tanpa melempar keluar bentuk lain', () => {
  assert.throws(() => verifyToken('bukan-token'));
  assert.throws(() => verifyToken(''));
});