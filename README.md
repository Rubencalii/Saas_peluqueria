# Sistema de Reservas · Cadena de Peluquerías

Plataforma de reservas multi-sede con dos canales sobre un **backend único**:
web responsive y bot de WhatsApp, más notificaciones automáticas. Diseño
white-label (tema propio por sede). Ver `docs/` para la especificación completa.

## Estado

### Hecho y verificado

- ✅ Documentación completa (`docs/00`–`13`, v0.2)
- ✅ Modelo de datos en PostgreSQL (`db/migrations/0001_init.sql`) — 15 tablas, enums, índices
- ✅ Anti-doble-reserva a nivel de BD (`db/migrations/0002_overlap_protection.sql`) — restricción de exclusión + trigger de tramos ocupados
- ✅ Soporte de servicios con tiempos muertos (tintes) en el modelo
- ✅ Datos de demostración (`db/seed.sql`) — 2 sedes, 3 profesionales, 4 servicios, usuarios
- ✅ Pruebas de concurrencia y tiempos muertos (`db/tests/overlap_and_deadtime.sql`) — 6 escenarios OK
- ✅ Entorno Docker (`docker-compose.yml`) — Postgres en `localhost:5446`
- ✅ Backend Symfony 7 + PHP 8.5 conectado a la BD vía Doctrine DBAL (`backend/`)
- ✅ `GET /api/v1/locations` — sedes activas
- ✅ `GET /api/v1/locations/{slug}/services` — servicios y precio por sede
- ✅ `GET /api/v1/availability` — cálculo de huecos (algoritmo doc 02), probado vía curl
- ✅ `POST /api/v1/appointments` — creación atómica (la BD devuelve 409 al solapar), idempotencia por `Idempotency-Key`
- ✅ Gestión de cita: lookup / reprogramar / cancelar (verificación por `public_code`, antelación mínima 2h)
- ✅ Webhook + bot de WhatsApp (Cloud API) — máquina de estados por botones/listas: reservar, ver, cambiar, cancelar y atención humana; reutiliza la misma lógica que la web. Probado de extremo a extremo (modo local: salida al log sin credenciales de Meta)
- ✅ API del panel con auth (JWT HS256 propio) + autorización por rol/sede:
  - `POST /api/v1/auth/login` · `GET /api/v1/admin/me`
  - `GET /api/v1/admin/agenda` (día/semana por sede)
  - `POST|PATCH|DELETE /api/v1/admin/appointments` (alta manual, estado/notas, cancelar)
  - `GET /api/v1/admin/customers` · `GET|PATCH /api/v1/admin/customers/{id}` (CRM)
  - `GET /api/v1/admin/conversations` · `POST /api/v1/admin/conversations/{waId}/reply` (bandeja de atención humana de WhatsApp)
  - Probado de extremo a extremo vía curl (login, 401/403, aislamiento por sede)
- ✅ API de configuración del panel + informes (doc 06 §4), con autorización por rol:
  - Servicios: `GET|POST|PATCH /api/v1/admin/services` (catálogo de cadena con segmentos de tiempo muerto y precio por sede; CRUD admin_cadena)
  - Personal: `GET|POST|PATCH /api/v1/admin/staff` y horario semanal `GET|POST /api/v1/admin/staff/{id}/schedule`
  - Bloqueos: `GET|POST /api/v1/admin/time-blocks` · `DELETE /api/v1/admin/time-blocks/{id}`
  - Sedes: `GET|POST|PATCH /api/v1/admin/locations` (admin_cadena) · branding `GET|PATCH /api/v1/admin/locations/{id}/branding`
  - Informes: `GET /api/v1/admin/reports/{occupancy,no-shows,bookings-by-channel}`
  - Probado de extremo a extremo vía curl

### Pendiente

- ⏳ Panel de administración (frontend)
- ⏳ Web pública de reserva
- ⏳ Notificaciones automáticas (recordatorios)

## Arranque rápido (base de datos)

Requiere Docker.

```bash
docker compose up -d
# Postgres en localhost:5446 con migraciones + datos de demo aplicados
```

Detalles, conexión y pruebas: ver [`db/README.md`](db/README.md).

## Documentación

Empezar por `docs/00-indice.md`. Rutas de lectura:

- **Definir el proyecto:** 01 → 02 → 08
- **Backend:** 02 (disponibilidad) → 05 (datos) → 06 (API)
- **Web/panel:** 04 → 06 → 08
- **WhatsApp:** 03 → 07 → §8 del 01

## Decisiones de arquitectura ya tomadas

- PostgreSQL como fuente única de verdad; horas en **UTC**.
- Anti-doble-reserva garantizado **a nivel de BD** (restricción de exclusión
  sobre tramos ocupados, compatible con servicios de tiempos muertos).
- Multi-tenant por configuración (tema por sede); cliente único por teléfono.

## Decisiones abiertas (de `docs/00`)

1. Bot por botones vs. IA conversacional (recomendado: botones en el MVP).
2. ¿Pago online en el alcance inicial? (por defecto, fuera).
3. ¿Dominio propio por sede o sólo tema propio?
4. ¿Servicios con tiempos muertos desde el MVP? → **Sí** (ya soportado en el modelo).
