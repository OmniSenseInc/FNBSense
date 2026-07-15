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
- `reporting/` — Laravel (F6)
- `dashboard/` — Laravel + Filament (F6)
- `notification/` — Node (F8)
- `hermes/` — Python (F8)
