# API Gateway (Traefik)

Traefik dikonfigurasi lewat **Docker provider** (label pada tiap service), jadi routing muncul otomatis saat service dinyalakan. Konfigurasi statis ada di command `gateway` pada `docker-compose.yml` root.

## Cara service mendaftarkan route

Tambahkan label pada service di compose miliknya, contoh untuk IAM:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.iam.rule=Host(`iam.localhost`)"
  - "traefik.http.routers.iam.entrypoints=web"
  - "traefik.http.services.iam.loadbalancer.server.port=80"
```

Konvensi host dev: `<service>.localhost` (mis. `iam.localhost`, `catalog.localhost`) — otomatis resolve ke 127.0.0.1.

Dashboard Traefik: http://localhost:8080
