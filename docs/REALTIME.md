# Blueprint — F3 Realtime (Node) + Relay Outbox

Status: **desain terkunci** (disetujui Vincent 2026-07-18). Belum ada kode.
Prasyarat: Ordering (`db0242b`) menulis baris `outbox` di transaksi PAID; RabbitMQ & Redis
sudah ada di `docker-compose.yml` root. Lihat [[project-f3-realtime-node]].

## Tujuan

Menyalakan jalur peristiwa: order yang **PAID** di Ordering harus sampai ke consumer
lain (KDS dapur, nanti Inventory/Finance) **tanpa Ordering tahu siapa consumer-nya**.
Perekat: **Transactional Outbox → Relay → RabbitMQ → Consumer**.

Ordering tetap satu-satunya pemilik status order. Relay **cuma pengangkut**: tidak
menyusun ulang event, tidak menyentuh status. Amplop event sudah jadi & final di kolom
`outbox.payload` (ditulis langkah 8, sesuai `shared/contracts/events/order-paid.event.json`).

## Slicing F3

| Sub | Isi | Status |
|---|---|---|
| **F3a** | Relay `outbox:relay` (daemon) + topologi RabbitMQ + **consumer bukti** (Node, log-only) untuk membuktikan jalur end-to-end | **fokus dokumen ini** |
| **F3b** | Service Realtime/KDS: consumer bukti dinaikkan jadi server WebSocket (`ws`), auth JWT, broadcast ke layar dapur | menyusul |
| **F3c** | Printing (tiket/struk fisik) | **DITUNDA** — layar KDS menggantikan tiket dapur untuk sekarang |

## Alur F3a

```
confirm-payment (kasir) ── 1 transaksi ──→ orders.status=PAID + outbox row (published_at=NULL)
                                                    │
        ┌───────────────────────────────────────────┘
        ▼
outbox:relay (daemon, loop ~1 dtk)
  ambil batch WHERE published_at IS NULL, ORDER BY occurred_at
  → publish payload ke exchange `fnbsense.events` (routing_key = event_type)
  → tunggu publisher-confirm broker
  → BARU set published_at = now()          ← urutan ini yang bikin at-least-once
        │
        ▼  RabbitMQ topic exchange `fnbsense.events`
        │     routing_key "order.paid" → bind ke queue consumer
        ▼
consumer bukti (Node + amqplib)
  dedup by event_id → log "order.paid <id>" → ACK
```

## Keputusan yang dikunci

| # | Keputusan | Alasan |
|---|---|---|
| 1 | Exchange **`fnbsense.events`**, tipe **topic**, durable. Routing key = `event_type` (mis. `order.paid`). | Topic = consumer bebas pilih pola (`order.*`, `#`) tanpa publisher tahu. Satu exchange untuk semua domain event, decoupling maksimal. |
| 2 | Relay = **daemon** `php artisan outbox:relay` (loop, sleep ~1 dtk saat kosong), **bukan** scheduler. Ada flag `--once` untuk test & fallback cron. | KDS harus terasa realtime (detik). Scheduler termin 30–60 dtk terlalu lambat. Daemon dikelola process manager (Supervisor/pm2). |
| 3 | **Publisher confirms**: `published_at` diisi **hanya setelah** broker meng-ack terima pesan. Publish gagal → baris tetap `NULL` → dicoba lagi loop berikut. | Tanpa confirm, relay bisa "yakin terkirim" padahal pesan hilang di jaringan. Ini pertahanan inti *at-least-once*. |
| 4 | Pengiriman **at-least-once** → **consumer wajib idempoten**, dedup by `event_id`. Duplikat mungkin terjadi (publish sukses tapi update DB relay gagal → kirim ulang). | Exactly-once di sistem terdistribusi itu mahal/ilusif. Lebih murah: kirim ulang boleh, consumer buang duplikat. `event_id` (uuid) sudah ada di amplop. |
| 5 | **DLX dipasang dari awal.** Queue kerja punya `x-dead-letter-exchange` → exchange `fnbsense.events.dlx` → queue `fnbsense.dead`. | Queue di RabbitMQ **immutable**: menambah argumen `x-dead-letter-*` nanti = hapus & buat ulang queue (ribet saat consumer sudah jalan). Murah sekarang, mahal retrofit. Pesan racun tak diam-diam hilang. |
| 6 | Queue **durable** + pesan **persistent** (`deliveryMode=2`). | Broker restart tak boleh menelan event uang. |
| 7 | Consumer bukti F3a ditulis **Node (TypeScript + amqplib)** di `services/realtime`, bukan skrip PHP sekali-pakai. | Sekalian scaffold service yang F3b lanjutkan jadi WebSocket. Nol kode terbuang. |
| 8 | **Setiap pihak assert topologi yang dia butuh** saat start (`assertExchange`/`assertQueue`/`bindQueue`, semua idempoten). Tak ada skrip "migration broker" terpisah. | Relay butuh exchange; consumer butuh queue+bind. `assert*` aman dipanggil berulang. Service bisa start dengan urutan bebas. |

## Topologi RabbitMQ

```
                    ┌─────────────────────────────┐
  relay publish ──▶ │ exchange fnbsense.events     │ (topic, durable)
   rk=order.paid    └──────────────┬──────────────┘
                                   │ bind rk="order.paid" (F3b: "order.#")
                                   ▼
                    ┌─────────────────────────────┐
                    │ queue realtime.orders        │ durable
                    │  x-dead-letter-exchange:     │
                    │    fnbsense.events.dlx        │
                    └──────────────┬──────────────┘
                        nack(requeue=false) / TTL habis
                                   ▼
                    ┌─────────────────────────────┐
  (nanti dipantau)  │ exchange fnbsense.events.dlx │ (fanout, durable)
                    └──────────────┬──────────────┘
                                   ▼
                    ┌─────────────────────────────┐
                    │ queue fnbsense.dead          │ durable
                    └─────────────────────────────┘
```

- **Naming** (konstanta bersama, jangan hardcode terserak):
  - exchange event: `fnbsense.events`
  - DLX: `fnbsense.events.dlx`
  - dead queue: `fnbsense.dead`
  - queue consumer realtime: `realtime.orders`
- Queue consumer = **per-consumer**, bukan per-event. Consumer bind pola routing key yang
  dia minati. Consumer baru (Inventory) = queue baru + bind sendiri, **nol** perubahan relay.
- **Prefetch** consumer = 1–10 (jangan unbounded) supaya satu consumer lambat tak menyedot
  semua pesan sekaligus.

## Relay: kontrak perilaku (WAJIB dipatuhi)

Ditulis eksplisit karena ini titik paling gampang salah:

1. **Baca**: `SELECT ... WHERE published_at IS NULL ORDER BY occurred_at LIMIT <batch>`.
   Batch mis. 100. Index `published_at` sudah ada.
2. **Publish per baris**: body = `outbox.payload` (JSON amplop utuh, jangan diubah),
   `routing_key = outbox.event_type`, `persistent=true`, `mandatory=true`.
3. **Tunggu confirm** broker untuk baris itu.
4. **Baru** `UPDATE outbox SET published_at = now() WHERE id = ?`.
5. Urutan 3→4 tak boleh dibalik. Kalau proses mati di antara 3 dan 4 → baris tetap `NULL`
   → loop berikut kirim **ulang** → consumer dedup by `event_id`. (Ini konsekuensi
   at-least-once yang memang diterima, bukan bug.)
6. Batch kosong → `sleep(1)`. Batch terisi → langsung loop lagi (drain antrean backlog).
7. **Graceful shutdown**: tangkap `SIGTERM`/`SIGINT`, selesaikan baris yang sedang jalan,
   tutup channel, keluar `0`. Supaya Supervisor/pm2 restart bersih.
8. Koneksi broker putus → reconnect dengan backoff, **jangan** tandai apa pun published saat
   tak yakin. Relay yang ragu memilih kirim-ulang, bukan tandai-terkirim.
9. Flag `--once`: satu pass batch lalu keluar (dipakai test & fallback `schedule` darurat).

## Idempotensi consumer (kontrak semua consumer, sekarang & nanti)

- Simpan `event_id` yang sudah diproses (F3a: `Set` in-memory cukup untuk bukti; F3b+:
  Redis/DB dengan TTL). Sebelum memproses, cek sudah pernah? → ACK & buang.
- Proses **sukses** → ACK. Proses **gagal permanen** (payload rusak/tak dikenal) →
  `nack(requeue=false)` → masuk DLQ. Gagal **sementara** (dependensi down) → `nack(requeue=true)`
  atau biarkan channel close supaya redeliver.
- Consumer **tidak mengubah** status order. Ia bereaksi (tampilkan di KDS), bukan sumber kebenaran.

## Skrutini keamanan (jalur uang → wajib)

1. **Kredensial broker dari env** (`RABBITMQ_*`), tak pernah hardcode. Password dev di
   `docker-compose.yml` (`change_me_in_dev`) **wajib diganti** sebelum non-dev.
2. **Amplop event minimal PII**: cek — `order.paid` **tidak** memuat `customer_name` (sengaja).
   Jangan tambahkan. Relay & consumer **jangan** log payload penuh di level info.
3. **Consumer tak percaya pesan mentah**: validasi bentuk amplop (ada `event_id`, `event_type`,
   `tenant_id`) sebelum pakai. Pesan tak berbentuk → DLQ, bukan crash.
4. **Isolasi tenant tetap berlaku di hilir**: `tenant_id`/`outlet_id` ada di amplop; consumer
   (F3b, saat broadcast ke KDS) harus mengarahkan hanya ke layar outlet yang benar.
5. **DLQ wajib bisa dipantau** sejak ada. Pesan gagal tak boleh hilang senyap — itu bisa berarti
   stok tak terpotong / penjualan tak tercatat nanti.
6. Publisher `mandatory=true` + tangani `basic.return`: pesan yang **tak ter-route** (tak ada
   queue cocok) jangan dianggap terkirim.

## Urutan implementasi F3a (satu langkah = satu review — mode Vincent)

1. **Env + config broker** di `services/ordering`: `RABBITMQ_HOST/PORT/USER/PASS/VHOST` +
   `config/rabbitmq.php`. Nama exchange/queue/DLX jadi **konstanta bernama** (satu tempat).
2. **Package** `php-amqplib/php-amqplib` (composer).
3. **Topology helper** (assert exchange `fnbsense.events` + DLX + dead queue, idempoten).
4. **Command `outbox:relay`** sesuai "kontrak perilaku" di atas (daemon + `--once` + confirms +
   graceful shutdown).
5. **Test relay bergigi** (mock channel AMQP): (a) publish dipanggil dengan routing key =
   `event_type` & body = payload utuh; (b) `published_at` diisi **hanya** setelah confirm;
   (c) publish gagal → `published_at` tetap `NULL`; (d) baris yang sudah `published_at != NULL`
   tak dikirim ulang. Buktikan bergigi via mutasi (balik urutan confirm↔update → test (b) merah).
6. **Scaffold `services/realtime`** (Node + TypeScript + amqplib): assert queue `realtime.orders`
   + bind `order.paid` → DLX; consume → dedup by `event_id` → log → ACK.
7. **Bukti end-to-end** (manual): `docker compose up -d rabbitmq` → jalankan relay + consumer →
   `confirm-payment` satu order → consumer nge-log `order.paid`. Matikan consumer, bayar lagi,
   nyalakan consumer → event tetap terkirim (backlog outbox tak hilang).

## F3b — Server WebSocket KDS (SELESAI 2026-07-19)

Consumer F3a dinaikkan jadi **server WebSocket**: event `order.paid` di-broadcast ke
layar dapur (KDS) yang tersambung, di-scope per outlet.

Keputusan (dikunci):
| # | Keputusan | Alasan |
|---|---|---|
| 1 | Auth WS: **JWT di query** `?token=<JWT>`, verifikasi RS256 saat handshake, gagal → `close(4401)`. | Browser WebSocket API tak bisa set header Authorization. Verifikasi public-key-only, pola sama service Laravel — tak query IAM. |
| 2 | Broadcast **di-scope per `outlet_id`**: klien dikelompokkan `Map<outlet_id, Set<socket>>`; event hanya ke klien outlet yang cocok. | `outlet_id` UUID unik global = batas tenant+outlet (pola terbukti Ordering). Isolasi realtime. |
| 3 | Token tanpa `outlet_id` **ditolak** (owner belum terikat outlet). | KDS bekerja pada satu outlet konkret; sama seperti endpoint Ordering. |
| 4 | Public key via **path relatif** `../ordering/storage/keys/jwt-public.pem`. | Lintas-OS — sekaligus melunasi utang path absolut Windows di sisi Node. |
| 5 | Klien menerima `{type:"connected"}` saat handshake, lalu `{type:"order.paid", event}` per event. | Kontrak pesan sederhana untuk frontend KDS (F-frontend). |

Modul: `src/auth.ts` (verifyToken), `src/kds-server.ts` (KdsServer: registry + broadcast), `src/index.ts` (consumer → `broadcastToOutlet`). Dep baru: `ws`, `jsonwebtoken`.

**Terbukti E2E (2026-07-19):** klien uji (token RS256 outlet o-demo) connect → publish `order.paid` ke exchange → server consume → broadcast diterima klien. Auth lolos (`connected`), isolasi outlet jalan.

**Utang F3b (belum, sengaja):**
- **Heartbeat ping/pong belum ada.** Koneksi KDS idle seharian bisa di-reap NAT/proxy tanpa ping. Server WS produksi WAJIB heartbeat (deteksi layar mati + jaga koneksi). Prioritas #1 pengerasan F3b. (Catatan: close abnormal 1006 saat uji background = artefak harness suspend, bukan bug — tapi memperkuat perlunya heartbeat di produksi.)
- Dedup `event_id` masih in-memory (warisan F3a) — hilang saat restart.
- Belum ada test otomatis untuk KdsServer (auth reject, scoping per outlet) — E2E manual dulu.

## Catatan / utang teknis

- **`JWT_PUBLIC_KEY` pakai path absolut Windows** (`file://C:/laragon/...`) di service Laravel —
  akan pecah di Docker/Linux. F3b (consumer Node verifikasi JWT untuk auth WebSocket) titik tepat
  membereskan ini. Belum menghalangi F3a (relay tak butuh JWT).
- Consumer bukti F3a **belum** WebSocket & **belum** verifikasi JWT — itu F3b. F3a hanya
  membuktikan pipa relay→broker→consumer hidup.
- Multi-instance relay (skala): butuh `FOR UPDATE SKIP LOCKED` saat ambil batch supaya dua
  instance tak kirim baris sama. F3a single-instance → belum perlu, tapi query ditulis agar
  gampang dinaikkan.
- Backlog `outbox` lama (baris `published_at=NULL` yang menumpuk sejak F2) akan **ikut terkirim**
  begitu relay pertama kali jalan — normal. Kalau tak diinginkan saat dev, tandai manual dulu.
