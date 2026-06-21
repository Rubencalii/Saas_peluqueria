# Backend (Symfony)

API REST del sistema de reservas (`docs/06-especificacion-api.md`). PHP 8.5 +
Symfony 7. Usa **Doctrine DBAL** (SQL) contra la BD de `../db`, que es la fuente
de verdad: la lógica anti-doble-reserva y los tramos ocupados viven en PostgreSQL
(restricción de exclusión + trigger), no en el ORM.

## Requisitos

- La base de datos en marcha: desde la raíz del repo, `docker compose up -d`
  (Postgres en `localhost:5446`). Ver `../db/README.md`.
- PHP 8.5 con `pdo_pgsql`, Composer.

## Arrancar

```bash
composer install
php -S 127.0.0.1:8000 -t public      # o:  symfony serve -d
```

Conexión configurada en `.env` (`DATABASE_URL`, puerto 5446).

## Endpoints implementados

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v1/locations` | Sedes activas |
| GET | `/api/v1/locations/{slug}/services` | Servicios y precio por sede |
| GET | `/api/v1/availability` | Huecos disponibles |
| POST | `/api/v1/appointments` | Crear reserva (atómica, 409 si solapa) |
| GET | `/api/v1/appointments/lookup` | Consultar citas (phone + code) |
| PATCH | `/api/v1/appointments/{id}/reschedule` | Reprogramar (atómica) |
| DELETE | `/api/v1/appointments/{id}` | Cancelar (libera el hueco) |

### `GET /api/v1/availability`

Parámetros: `location_id`, `service_id`, `date` (YYYY-MM-DD), `staff_id` (opcional).

```bash
curl "http://127.0.0.1:8000/api/v1/availability?location_id=1&service_id=1&date=2026-06-22"
```

```json
{
  "date": "2026-06-22",
  "slots": [
    { "start": "2026-06-22T07:00:00+00:00", "staff_id": 1 }
  ]
}
```

Implementa el algoritmo de `docs/02-logica-disponibilidad.md`:

- Disponibilidad = horario − citas/tramos ocupados − bloqueos − margen.
- Rejilla de 15 min; antelación mínima de 30 min; no ofrece huecos en el pasado.
- **Servicios con tiempos muertos**: sólo los segmentos activos del servicio
  (`service_segment`) ocupan al profesional; durante el reposo el hueco se ofrece
  a otra cita.
- Horas en **UTC** (ISO 8601); el cliente las convierte a la zona de la sede.
- `staff_id` omitido = "sin preferencia": se ofrece cada hora una vez.

### `POST /api/v1/appointments`

Crea una reserva. Valida que el hueco siga ofertado y resuelve el profesional
("sin preferencia" si `staff_id` es `null`). La protección anti-doble-reserva es
de la BD: el trigger materializa los tramos ocupados y la restricción de
exclusión rechaza solapes → la API responde **409 `SLOT_TAKEN`**.

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/appointments" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: abc-123" \
  -d '{"location_id":1,"service_id":2,"staff_id":3,
       "start":"2026-06-22T09:00:00+02:00",
       "customer":{"name":"Pedro","phone":"+34699999999","email":null},
       "wa_consent":true,"channel":"web"}'
```

- **201** con `{appointment_id, status, staff_id, start, end}`.
- El cliente se identifica por teléfono (upsert: crea o actualiza).
- **Idempotencia**: cabecera opcional `Idempotency-Key`; un reintento con la
  misma clave devuelve **200** con la cita ya creada (`idempotent_replay: true`).
- Errores: `400 VALIDATION`, `409 SLOT_TAKEN`.
- La respuesta incluye un `public_code`: el cliente lo necesita para consultar,
  reprogramar o cancelar su cita sin login (se entregará en la confirmación).

### Gestión de la propia cita (sin login)

Verificación ligera: cada cita lleva un `public_code` aleatorio. El código se
acepta en la cabecera `X-Appointment-Code`, en `?code=` o en el campo `code` del
cuerpo. Política de antelación: hasta **2 h** antes; más cerca de la cita se
devuelve `409 TOO_LATE` (derivar a la sede).

```bash
# Consultar próximas citas del cliente
curl "http://127.0.0.1:8000/api/v1/appointments/lookup?phone=%2B34655000111&code=CODE"

# Reprogramar (atómico: si el nuevo hueco choca, revierte y conserva el original)
curl -X PATCH "http://127.0.0.1:8000/api/v1/appointments/2/reschedule" \
  -H "Content-Type: application/json" -H "X-Appointment-Code: CODE" \
  -d '{"start":"2026-06-22T09:30:00+02:00"}'

# Cancelar (libera el hueco al instante; idempotente)
curl -X DELETE "http://127.0.0.1:8000/api/v1/appointments/2?code=CODE"
```

- **Reprogramar** valida horario/antelación y deja que la BD resuelva carreras
  (409 `SLOT_TAKEN`); usa el mismo profesional de la cita.
- **Cancelar** pone la cita en `cancelada` (el trigger libera el hueco); repetir
  la cancelación devuelve 200 (idempotente).
- Errores: `400 VALIDATION`, `404 NOT_FOUND` (código/teléfono no casan),
  `409 TOO_LATE`, `409 SLOT_TAKEN`, `409 INVALID_STATE`.

## Pendiente

- Webhook de WhatsApp y endpoints internos del panel (auth + roles).

## Código

- `src/Service/AvailabilityService.php` — algoritmo de huecos.
- `src/Service/AppointmentService.php` — creación atómica de reservas.
- `src/Service/AppointmentException.php` — error de negocio (código + HTTP).
- `src/Controller/AvailabilityController.php` — endpoint de disponibilidad.
- `src/Controller/AppointmentController.php` — reservas + lookup/reschedule/cancel.
- `src/Controller/CatalogController.php` — sedes y servicios.
