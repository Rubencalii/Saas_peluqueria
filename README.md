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
- ✅ Motor de notificaciones y recordatorios (doc 07): al crear/cambiar/cancelar una cita se programan automáticamente confirmación, recordatorio (24 h antes) y avisos de cambio/cancelación en la tabla `notification`. El comando `php bin/console app:notifications:dispatch` (para cron) entrega las vencidas por WhatsApp y marca `enviada`/`fallida`. Probado de extremo a extremo (con/sin consentimiento, cancelación, recordatorio futuro)
- ✅ Suite de tests automatizados (PHPUnit, doc 10): **57 tests** sobre la lógica crítica — disponibilidad y tiempos muertos, condición de carrera (409), idempotencia, rollback de reprogramación, cancelación, JWT/roles, redacción de notificaciones, lista de espera, feed iCal, reset de contraseña, RGPD, degradación de pagos y **aislamiento multi-tenant** (test funcional HTTP). Integración contra BD de test aislada con rollback por transacción
- ✅ Endurecimiento de la API (doc 06 §6): rate limiting por IP en endpoints públicos (60/min) y en el login (10/min), errores uniformes en JSON bajo `/api`, y runner de migraciones versionadas `php bin/console app:db:migrate`
- ✅ Seguridad: firma del webhook de WhatsApp (`X-Hub-Signature-256`), reset de contraseña del panel (token de un solo uso), secretos fuera del repo
- ✅ Operación / pre-frontend: **CORS** (`/api/*`), **health check** (`/api/v1/health`), **contrato OpenAPI** ([`docs/openapi.yaml`](docs/openapi.yaml)) y **CI** (GitHub Actions con Postgres + tests)
- ✅ RGPD (doc 09): export de datos del cliente, anonimización (derecho de supresión) y baja de avisos ("BAJA")

- ✅ IA conversacional en el bot de WhatsApp (decisión doc 00): capa de comprensión de lenguaje natural con Claude (SDK oficial, salida estructurada) que deduce intención + servicio/profesional/fecha de un mensaje libre ("quiero un corte mañana con Laura") y arranca el flujo de reserva ya existente. Se desactiva sin `ANTHROPIC_API_KEY` (el bot funciona solo con botones). Modelo configurable con `ANTHROPIC_MODEL` (por defecto `claude-opus-4-8`)

- ✅ Features del backlog (doc 13):
  - Recordatorio de retorno "te toca volver" (§2.3): comando `app:notifications:return-reminders --weeks=N` (cron) que avisa a clientes que no vuelven hace ~N semanas, reutilizando el motor de notificaciones (con consentimiento, idempotente)
  - Informes avanzados (§2.8): `GET /api/v1/admin/reports/{revenue,peak-hours,retention}` — ingresos por profesional/servicio, horas punta y tasa de retención
  - Anti no-show (§2.2): `GET /api/v1/admin/reports/no-show-customers` — ranking de clientes con más ausencias
  - Lista de espera (§2.4): `POST /api/v1/waitlist` (alta pública, idempotente), bandeja en el panel `GET /api/v1/admin/waitlist` + `DELETE /{id}`, botón "🔔 Avísame" en el bot cuando el día está completo, y comando `app:waitlist:notify` (cron) que avisa por WhatsApp al liberarse un hueco (reutiliza el algoritmo de disponibilidad)
  - Sincronización con calendario (§2.6): feed iCal por profesional `GET /api/v1/calendar/{token}.ics` (solo lectura, suscribible en Google/Apple Calendar) + en el panel `GET /api/v1/admin/staff/{id}/calendar` y `POST .../calendar/rotate`
  - Depósito / pago online (§2.5): depósito por servicio (`deposit_amount`) cobrado con Stripe (PaymentIntent). `POST /api/v1/appointments/{id}/deposit` devuelve el `client_secret`; `POST /api/v1/webhooks/stripe` confirma el cobro. Desacoplado de la reserva y desactivado sin `STRIPE_SECRET_KEY`
- ✅ Runner de migraciones estrenado: `app:db:migrate --baseline` + migraciones `0006`–`0009` (waitlist, token de calendario, pagos, reset de contraseña) aplicadas a las BD dev y test

- 🟡 Multi-tenant / SaaS multi-salón (doc 15), en curso:
  - **Fase 1** ✅ cimientos: tablas `account`/`plan`/`subscription`, cuenta `principal` con los datos actuales, `account_id` en las tablas raíz y en el JWT, `GET /api/v1/admin/account`
  - **Fase 2** ✅ aislamiento del panel: unicidad por-cuenta (`location.slug`, `customer.phone`) y scoping por `account_id` de **todas** las consultas del panel, con test funcional de aislamiento
  - **Fase 3** ✅ público multi-tenant: la web resuelve la cuenta por **subdominio** (`TenantResolver`) y el bot por la **línea de WhatsApp** (`account.wa_phone_number_id` + `phone_number_id` del webhook); el catálogo y los endpoints públicos se acotan a la cuenta y el cliente se crea en la cuenta de la sede
  - **Fase 6** ✅ alta de salón: `POST /api/v1/signup` crea cuenta (`trial`) + suscripción `free` + primera sede + administrador y devuelve sesión (email de bienvenida best-effort)
  - **Fase 5** ✅ planes y facturación: **límites de plan** (`PlanLimitService`, 402), **cuenta suspendida ⇒ solo lectura** (escrituras del panel devuelven 402, salvo facturación) y **billing con Stripe** (`BillingService`: Checkout para alta/cambio de plan, Customer Portal y webhook propio que sincroniza estado de cuenta/suscripción — impago suspende, pago reactiva). Degrada sin claves
  - **Fase 4** ✅ Row-Level Security (red de seguridad en BD): rol `peluqueria_app` sin BYPASSRLS + políticas en las tablas raíz; `TenantSessionListener` fija `app.account_id` por petición. Es *opt-in* (la web se conecta como ese rol vía `DATABASE_URL`; migraciones/cron siguen con el owner). Test dedicado que prueba el aislamiento a nivel de BD

### Frontend

- ✅ **Web pública de reserva** ([`frontend/`](frontend/), Next.js App Router + TypeScript + Tailwind): elegir salón → servicio → día → hueco → datos → confirmar, más **Mi cita** (consultar/reprogramar/cancelar). Consume la API vía proxy (sin CORS en dev); tema white-label por variables CSS. Build de producción verde e integración verificada contra el backend.
- 🟡 **Panel de administración** (`frontend/` bajo `/panel`): login JWT, **agenda** del día (cambiar estado/cancelar citas), **clientes** (búsqueda, paginación y ficha con historial y fidelización), **servicios** (alta/edición del catálogo y sedes donde se ofrece), **personal** (alta/edición de profesionales con sus sedes y servicios + **horario semanal por sede**), **cuenta** (plan, límites y facturación con Stripe) y **apariencia** (white-label: nombre, colores y logo con vista previa). La agenda permite **alta manual de cita** (servicio → día → hueco → cliente), e **informes** (rango de fechas + sede: ingresos por servicio/profesional, reservas por canal, tasa de no-show, retención, valoraciones y, por sede, ocupación y horas punta). Faltan pantallas de configuración (sedes, lista de espera, conversaciones).
- ✅ **White-label por empresa** (doc 08): cada cuenta personaliza desde *Apariencia* el nombre, los colores y el **logo (subiendo la imagen** desde su equipo; se guarda como data-URL); el tema se aplica a su **web de reserva** (por subdominio) y a su **panel**. Las páginas públicas se renderizan por host (sin caché compartida entre tenants).
- ✅ **Super-admin de plataforma** (`/superadmin`): el operador del SaaS (rol `is_superadmin`, transversal a los tenants) ve todas las cuentas con su plan/estado y uso, y puede **suspender/reactivar** y **cambiar de plan**, además de métricas globales. Acceso vetado a los administradores de salón.

> El **backend está funcionalmente completo** (núcleo + backlog del doc 13) y el **multi-tenant está completo** (Fases 1-6, doc 15). En **frontend** están la **web pública de reserva** y el **núcleo del panel de administración** (`/panel`: login, agenda, clientes, cuenta); quedan las pantallas de configuración del panel.

## Arranque rápido (base de datos)

Requiere Docker.

```bash
docker compose up -d
# Postgres en localhost:5446 con migraciones + datos de demo aplicados
```

Detalles, conexión y pruebas: ver [`db/README.md`](db/README.md).

## Pruebas (backend)

La suite usa PHPUnit. Los tests de integración corren contra una base de datos
de test aislada (`peluqueria_test`) en el mismo Postgres de Docker; cada test
se ejecuta dentro de una transacción que se revierte, así que no deja rastro.

Preparar la BD de test una vez (carga esquema + datos de demo):

```bash
docker compose exec -T db psql -U peluqueria -d peluqueria -c "CREATE DATABASE peluqueria_test;"
for f in db/migrations/0001_init.sql db/migrations/0002_overlap_protection.sql \
         db/migrations/0003_idempotency.sql db/migrations/0004_appointment_public_code.sql \
         db/migrations/0005_whatsapp_conversation.sql db/seed.sql; do
  docker compose exec -T db psql -U peluqueria -d peluqueria_test -q < "$f"
done
```

Ejecutar la suite:

```bash
cd backend && php bin/phpunit
```

Cubre la lógica crítica: disponibilidad y tiempos muertos, condición de carrera
(409 al solapar), idempotencia, rollback de reprogramación, JWT/roles y la
redacción de notificaciones.

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

## Decisiones (de `docs/00`)

1. Bot por botones vs. IA conversacional → **ambos**: botones como base + capa de IA opcional (se activa con `ANTHROPIC_API_KEY`).
2. ¿Pago online en el alcance inicial? → **implementado como depósito por servicio** (Stripe, opcional; se activa con `STRIPE_SECRET_KEY`).
3. ¿Dominio propio por sede o sólo tema propio? → tema propio listo (branding por sede); el `custom_domain` se guarda pero el enrutado por dominio queda para despliegue. *(abierta)*
4. ¿Servicios con tiempos muertos desde el MVP? → **Sí** (soportado en el modelo y la disponibilidad).
