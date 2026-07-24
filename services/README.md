# services/

Satu folder per microservice. Tiap service berdiri sendiri: punya dependency, DB, dan `Dockerfile`/compose fragment sendiri.

Rencana pengisian (per fase roadmap):

- `iam/` — Laravel (F0)
- `catalog/` — Laravel (F1)
- `ordering/` — Laravel (F2)
- `realtime/` — Node (F3)
- `printing/` — Laravel (F3)
- `inventory/` — Laravel (F4)
- `finance/` — Laravel (F5)
- `reporting/` — Laravel (F6a selesai; read-model analytics event-driven, MySQL + RabbitMQ)
- `dashboard/` — Laravel + Filament (F6b MVP selesai; BFF owner untuk IAM + Reporting)
- `notification/` — Node (F8)
- `hermes/` — Python (F8)
