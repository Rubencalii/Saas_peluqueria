# 14 · Estado del Backend

> Actualización a **2026-06-21**. Inventario de lo implementado en `backend/`
> (Symfony 7 + PHP 8.5, Doctrine DBAL sobre PostgreSQL) y backlog técnico
> pendiente. El backend está **completo a nivel de funcionalidad de producto**
> (núcleo + backlog del doc 13); lo que queda es endurecimiento y frontend.

## 1. Resumen

| Área | Estado |
|------|--------|
| Modelo de datos + anti-doble-reserva (BD) | ✅ |
| Disponibilidad (algoritmo doc 02, tiempos muertos) | ✅ |
| Reservas: alta atómica, lookup, reprogramar, cancelar | ✅ |
| Bot de WhatsApp (Cloud API) + IA conversacional | ✅ |
| API del panel: auth (JWT) + roles + agenda/citas/CRM | ✅ |
| Configuración del panel: servicios, personal, horarios, bloqueos, sedes, branding | ✅ |
| Notificaciones y recordatorios (confirmación, 24 h, cambio/cancelación) | ✅ |
| Informes: ocupación, no-shows, canal, ingresos, horas punta, retención | ✅ |
| Backlog doc 13: anti no-show, recordatorio de retorno, lista de espera, iCal, depósito Stripe | ✅ |
| Endurecimiento: rate limiting, errores uniformes, runner de migraciones | ✅ |
| Seguridad: firma webhook WhatsApp, rate limit login, reset de contraseña | ✅ |
| Operación: CORS, health check, OpenAPI, CI (GitHub Actions) | ✅ |
| RGPD (doc 09): export, anonimización, baja de consentimiento | ✅ |
| Suite de tests (PHPUnit) | ✅ 42 tests |
| Frontend (panel + web pública) | ⏳ pendiente |

## 2. Stack

- **PHP 8.5**, **Symfony 7** (FrameworkBundle).
- **PostgreSQL 16** (Docker, `localhost:5446`), acceso con **Doctrine DBAL** (SQL plano; la BD es la fuente de verdad, sin ORM).
- Dependencias clave: `symfony/rate-limiter`, `symfony/test-pack` (PHPUnit 13), `anthropic-ai/sdk` (IA del bot), `stripe/stripe-php` (depósitos).

## 3. Arranque

```bash
# 1. Base de datos (Docker)
docker compose up -d                     # Postgres en localhost:5446 (0001/0002/seed en primer arranque)

# 2. Migraciones pendientes (0003+)
cd backend
php bin/console app:db:migrate --baseline   # solo la primera vez en una BD ya provisionada
php bin/console app:db:migrate              # aplica las pendientes

# 3. Servidor de desarrollo
php -S 127.0.0.1:8000 -t public

# 4. Tests (requiere BD de test peluqueria_test, ver README §Pruebas)
php bin/phpunit
```

## 4. Migraciones (`db/migrations/`)

| Archivo | Contenido |
|---------|-----------|
| `0001_init.sql` | Esquema base: 15 tablas, enums, índices |
| `0002_overlap_protection.sql` | Anti-solape: `EXCLUDE` sobre tramos ocupados + trigger |
| `0003_idempotency.sql` | Idempotencia de reservas (`idempotency_key`) |
| `0004_appointment_public_code.sql` | Código público por cita (gestión sin login) |
| `0005_whatsapp_conversation.sql` | Estado del bot (`conversation`, `wa_processed_message`) |
| `0006_waitlist.sql` | Lista de espera (`waitlist` + enum) |
| `0007_staff_calendar_token.sql` | Token de feed iCal por profesional |
| `0008_payments.sql` | Depósito por servicio + tabla `payment` |

Las migraciones se aplican con el runner versionado `app:db:migrate` (registra en `schema_migration`; opciones `--status`, `--baseline`).

## 5. Endpoints

### 5.1 Públicos (sin login)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET`  | `/api/v1/locations` | Sedes activas |
| `GET`  | `/api/v1/locations/{slug}/services` | Servicios y precio por sede |
| `GET`  | `/api/v1/availability` | Huecos disponibles (algoritmo doc 02) |
| `POST` | `/api/v1/appointments` | Crear reserva (atómica, 409 al solapar, idempotente) |
| `GET`  | `/api/v1/appointments/lookup` | Consultar citas (teléfono + código) |
| `PATCH`| `/api/v1/appointments/{id}/reschedule` | Reprogramar (código) |
| `DELETE`| `/api/v1/appointments/{id}` | Cancelar (código) |
| `POST` | `/api/v1/waitlist` | Apuntarse a la lista de espera |
| `POST` | `/api/v1/appointments/{id}/deposit` | Iniciar pago del depósito (Stripe) |
| `GET`  | `/api/v1/calendar/{token}.ics` | Feed iCal de la agenda de un profesional |
| `GET`  | `/api/v1/health` | Health check (app + BD) |

> Contrato completo de la API en [`docs/openapi.yaml`](openapi.yaml) (OpenAPI 3.1). CORS habilitado en `/api/*` (orígenes en `CORS_ALLOWED_ORIGINS`).

> Rate limiting por IP: **60/min** en alta de reserva, lookup, disponibilidad y lista de espera; **10/min** en el login del panel (anti fuerza bruta).

### 5.2 Panel (requieren `Authorization: Bearer <JWT>` + rol)

**Auth:** `POST /api/v1/auth/login` · `GET /api/v1/admin/me` · reset de contraseña `POST /api/v1/auth/password/forgot` · `POST /api/v1/auth/password/reset`

| Área | Endpoints |
|------|-----------|
| Agenda | `GET /admin/agenda` (día/semana por sede) |
| Citas | `POST` `PATCH` `DELETE /admin/appointments[/ {id}]` |
| Clientes (CRM) | `GET /admin/customers` · `GET` `PATCH /admin/customers/{id}` |
| RGPD (clientes) | `GET /admin/customers/{id}/export` (acceso/portabilidad) · `DELETE /admin/customers/{id}` (anonimizar) — solo admin |
| Servicios | `GET` `POST /admin/services` · `PATCH /admin/services/{id}` |
| Personal | `GET` `POST /admin/staff` · `PATCH /admin/staff/{id}` · horario `GET` `POST /admin/staff/{id}/schedule` |
| Calendario (iCal) | `GET /admin/staff/{id}/calendar` · `POST /admin/staff/{id}/calendar/rotate` |
| Bloqueos | `GET` `POST /admin/time-blocks` · `DELETE /admin/time-blocks/{id}` |
| Sedes | `GET` `POST /admin/locations` · `PATCH /admin/locations/{id}` · branding `GET` `PATCH /admin/locations/{id}/branding` |
| Atención humana (WhatsApp) | `GET /admin/conversations` · `POST /admin/conversations/{waId}/reply` |
| Lista de espera | `GET /admin/waitlist` · `DELETE /admin/waitlist/{id}` |
| Informes | `GET /admin/reports/{occupancy,no-shows,bookings-by-channel,revenue,peak-hours,retention,no-show-customers}` |

**Roles:** `recepcion`, `profesional`, `admin_sede`, `admin_cadena` (autorización por sede; el catálogo y las sedes los gobierna `admin_cadena`).

### 5.3 Webhooks

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET`/`POST` | `/api/v1/webhooks/whatsapp` | Handshake de Meta + recepción de mensajes (dedup, **firma X-Hub-Signature-256 verificada**) |
| `POST` | `/api/v1/webhooks/stripe` | Confirmación de pago del depósito |

## 6. Comandos de consola (cron)

| Comando | Para qué |
|---------|----------|
| `app:db:migrate` | Aplica migraciones SQL versionadas (`--status`, `--baseline`) |
| `app:notifications:dispatch` | Entrega las notificaciones vencidas por WhatsApp |
| `app:notifications:return-reminders` | "Te toca volver" a clientes lapsados (`--weeks`, `--window`) |
| `app:waitlist:notify` | Avisa a la lista de espera al liberarse un hueco |

## 7. Variables de entorno

| Variable | Uso |
|----------|-----|
| `DATABASE_URL` | Conexión PostgreSQL |
| `APP_SECRET` | Secreto de la app **y** firma de los JWT del panel |
| `APP_URL` | URL pública del sitio (enlaces, p. ej. el de reset de contraseña) |
| `CORS_ALLOWED_ORIGINS` | Orígenes permitidos por CORS (lista; `*` = cualquiera) |
| `WHATSAPP_VERIFY_TOKEN` / `WHATSAPP_TOKEN` / `WHATSAPP_PHONE_NUMBER_ID` | Cloud API de WhatsApp (vacío → salida al log) |
| `WHATSAPP_APP_SECRET` | Verifica la firma del webhook de Meta (vacío → no se exige firma) |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | IA del bot (vacío → solo botones) |
| `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` | Depósitos (vacío → pagos desactivados) |

> **Degradación**: WhatsApp, IA y Stripe se **desactivan solos** si no hay credenciales, así que el backend funciona en local sin cuentas externas.

### Gestión de secretos

- Los archivos versionados (`.env`, `.env.dev`, `.env.test`) contienen **solo marcadores**, nunca secretos reales.
- El **secreto real de producción** (`APP_SECRET`, claves de WhatsApp/Anthropic/Stripe) va en **`.env.local`** (ignorado por git) o en variables de entorno del servidor.
- En despliegue, generar un `APP_SECRET` fuerte y aleatorio (firma los JWT del panel).

## 8. Tests

**42 tests** (PHPUnit). Unitarios puros (auth/JWT, redacción de notificaciones, degradación de pagos) e integración contra una BD de test aislada (`peluqueria_test`) con rollback por transacción: disponibilidad y tiempos muertos, condición de carrera (409), idempotencia, rollback de reprogramación, cancelación, lista de espera, feed iCal, reset de contraseña y RGPD (export/anonimización).

```bash
cd backend && php bin/phpunit
```

## 9. Pendiente (backlog técnico)

Funcionalmente no falta nada del MVP ni del doc 13. Lo recomendable antes de producción / del frontend:

### 🔴 Seguridad
- ✅ *Resuelto (2026-06-21):* **firma del webhook de WhatsApp** (`X-Hub-Signature-256`) verificada con HMAC del app secret (degrada sin `WHATSAPP_APP_SECRET`).
- ✅ *Resuelto (2026-06-21):* **rate limiting en el login** (`/auth/login`, 10/min por IP) contra fuerza bruta.
- ✅ *Resuelto (2026-06-21):* se retiró el `APP_SECRET` de alta entropía de los `.env` versionados (alerta de GitGuardian) y se **purgó del historial de git** (reescritura + force-push). Los secretos reales van en `.env.local`.
- ✅ *Resuelto (2026-06-21):* **reset de contraseña** del panel (token de un solo uso con caducidad, respuesta genérica anti-enumeración; enlace al log sin transporte de email). Opcional pendiente: **revocación/refresh** del JWT.

> Con esto el bloque 🔴 de seguridad queda cubierto (salvo el refresh/revocación de JWT, opcional).

### 🟠 Cumplimiento (RGPD, doc 09)
- ✅ *Resuelto (2026-06-21):* **export** de datos del cliente (acceso/portabilidad) y **anonimización** (derecho de supresión, conservando las citas por necesidad fiscal) — endpoints solo admin.
- ✅ *Resuelto (2026-06-21):* **baja de avisos por WhatsApp** ("BAJA" en el bot → retira el consentimiento).
- ⏳ Resto del doc 09 es **organizativo/legal** (política de privacidad, RAT, contratos de encargado, banner de cookies), fuera del backend.

### 🟡 Operación / preparación para el frontend
- ✅ *Resuelto (2026-06-21):* **CORS** en `/api/*` (`CorsListener`, orígenes configurables; preflight `OPTIONS`).
- ✅ *Resuelto (2026-06-21):* **OpenAPI** [`docs/openapi.yaml`](openapi.yaml) (OpenAPI 3.1, 45 rutas).
- ✅ *Resuelto (2026-06-21):* **Health check** `GET /api/v1/health` (app + BD).
- ✅ *Resuelto (2026-06-21):* **CI** (GitHub Actions): levanta Postgres, prepara la BD de test y corre lint + 38 tests en cada push/PR.
- ⏳ Opcional: **análisis estático (PHPStan)** en CI.

> Con esto el bloque 🟡 queda cubierto: el frontend ya tiene contrato (OpenAPI), CORS y health, y la CI protege cada cambio.

### ⚪ Producto (prioridad baja)
- Valoración post-cita, fidelización/puntos, citas recurrentes, audit log de acciones del panel, i18n de mensajes.

### Frontend (fuera de backend)
- **Panel de administración** y **web pública de reserva** (consumen esta API).

## 10. Decisiones tomadas

- **PostgreSQL como fuente de verdad**; horas en **UTC**; anti-doble-reserva a nivel de BD.
- **Bot por botones + IA opcional** (no excluyentes).
- **Pago online = depósito por servicio** con Stripe (opcional, desacoplado de la reserva).
- **Multi-sede** por configuración; cliente único por teléfono; tema (branding) por sede.
