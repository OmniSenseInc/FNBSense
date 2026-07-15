# Kontrak Event (source of truth)

Folder ini menyimpan **skema event** yang dipertukarkan antar-service lewat RabbitMQ. Skema di sini adalah kontrak resmi — service publisher & consumer harus patuh.

## Konvensi

- **Format:** JSON. Nama file `events/<nama-event>.event.json`.
- **Envelope wajib** di setiap event:
  - `event_id` — UUID v4, unik per event (dipakai consumer untuk idempotensi/dedup).
  - `event_type` — nama event, mis. `order.paid`.
  - `occurred_at` — waktu kejadian, ISO-8601 UTC (`2026-07-15T10:20:30Z`).
  - `tenant_id`, `outlet_id` — konteks multi-tenant.
  - `payload` — data spesifik event.
- **Idempotensi:** consumer menyimpan `event_id` yang sudah diproses dan mengabaikan duplikat.
- **Versioning:** perubahan tak kompatibel → naikkan versi via `event_type` (mis. `order.paid.v2`).

## Exchange & routing (RabbitMQ)

- Exchange utama: `fnbsense.events` (tipe `topic`).
- Routing key = `event_type` (mis. `order.paid`).
- Tiap consumer punya queue sendiri yang bind ke routing key yang diminati.
