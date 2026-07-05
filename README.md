# Peluquería SaaS · Reservas multi-salón

Plataforma **SaaS multi-tenant** de reservas para peluquerías y salones: web
pública de reserva (3 idiomas), **bot de WhatsApp**, panel de administración
completo y **consola de plataforma** para el operador del SaaS — todo sobre un
backend único con diseño **white-label** por cuenta.

## Qué incluye

**Para el cliente final (web pública, es/ca/en + bot de WhatsApp)**
- Reserva en 3 pasos (servicio → día y hora → datos) con confirmación al instante
  y código de gestión; *Mi cita* para consultar, reprogramar o cancelar.
- Bot de WhatsApp con botones y capa opcional de lenguaje natural (Claude);
  confirmaciones y recordatorios automáticos (WhatsApp con respaldo de email).
- Depósito online opcional por servicio (Stripe) y feed iCal por profesional.

**Para el salón (panel `/panel`)**
- Agenda día/semana con alta manual, próximo hueco por profesional, vista
  "mis citas" para cada profesional y estados (completada/no-show/cancelada).
- Clientes (CRM con RGPD: exportar/anonimizar, export CSV), **fidelización
  completa**: puntos automáticos, **bonos de sesiones** con canje automático
  idempotente y **tarjetas regalo** con código canjeable en caja.
- Lista de espera con aviso automático al liberarse hueco y conversión a cita,
  citas recurrentes, bandeja de WhatsApp (atención humana), valoraciones,
  informes con comparativas y evolución de 12 meses (export CSV), usuarios del
  equipo por rol, auditoría, **2FA (TOTP)**, apariencia white-label con paletas
  y prueba en vivo, y facturación de la suscripción (Stripe). Instalable como
  **PWA** en el móvil.

**Para el operador del SaaS (consola `/superadmin`)**
- Todas las cuentas con plan/estado/uso, ficha con contactos y actividad,
  suspender/reactivar, cambio de plan (con aviso si lo gestiona Stripe),
  **impersonación auditada** para dar soporte y altas por semana.
- Alta autoservicio de salones en `/alta` (cuenta + sede + admin, plan free).

**Seguridad y cumplimiento**
- JWT propio con revocación y sesión deslizante, 2FA TOTP (RFC 6238), rate
  limiting, CSP estricta, firma del webhook de WhatsApp (fail-closed),
  **Row-Level Security** en PostgreSQL por tenant, auditoría de todas las
  escrituras (panel y plataforma), RGPD (export/supresión/consentimiento) y
  purga programada de datos técnicos caducados. Sentry opcional (sin DSN, apagado).

## Stack

| Pieza | Tecnología |
|---|---|
| Backend / API | Symfony 7 · PHP 8.5 · Doctrine DBAL (SQL directo) |
| Base de datos | PostgreSQL 16 (anti-doble-reserva por restricción de exclusión + RLS) |
| Frontend | Next.js 16 (App Router) · React 19 · TypeScript · Tailwind 4 |
| Tests | PHPUnit (115+ contra BD real) · Vitest (67) · Playwright (E2E) |
| CI | GitHub Actions: PHPStan + PHPUnit + ESLint + Vitest + build + **smoke E2E** |

## Arranque rápido (desarrollo)

Requiere Docker, PHP ≥ 8.4 con `pdo_pgsql`, Composer y Node 22.

```bash
# 1. Base de datos (migraciones + datos de demo en el primer arranque)
docker compose up -d

# 2. Backend en :8000
cd backend && composer install
php -S 127.0.0.1:8000 -t public public/index.php

# 3. Frontend en :3000 (proxy /api → :8000, sin CORS)
cd frontend && npm install && npm run dev
```

| Qué | URL | Credenciales (seed) |
|---|---|---|
| Web pública de reserva | http://localhost:3000 | — |
| Panel del salón | http://localhost:3000/panel | `admin@salon.es` / `admin1234` |
| Consola de plataforma | http://localhost:3000/superadmin | `super@plataforma.es` / `super1234` |
| Alta de un salón nuevo | http://localhost:3000/alta | — |
| API health | http://localhost:8000/api/v1/health | — |

Las integraciones degradan sin credenciales: WhatsApp/email/Stripe/IA/Sentry
escriben al log en local. Secretos reales solo en `.env.local` (gitignorado).

## Pruebas

```bash
# Backend: PHPStan + suite completa contra peluqueria_test (rollback por test)
cd backend
vendor/bin/phpstan analyse --memory-limit=512M
php bin/phpunit

# Frontend: lint + unit + build
cd frontend
npm run lint && npm run test && npm run build

# E2E (arranca backend y frontend solo; requiere la BD de Docker)
cd frontend && npm run e2e
```

Si la BD de test no existe aún: créala y aplica `db/migrations/*.sql` + `db/seed.sql`
(ver `.github/workflows/ci.yml`, que hace exactamente eso).

## Producción

Todo empaquetado: imágenes Docker de backend (Apache+PHP) y frontend (Next
standalone), compose con TLS automático (Caddy), scheduler de crons y backups
diarios con retención. **Runbook completo en [`deploy/README.md`](deploy/README.md).**

Checklist previo (secretos, RLS, webhooks): `docs/11-despliegue-devops.md` §10.

## Estructura

```
backend/    API Symfony (src/Controller, src/Service, tests/)
frontend/   Next.js: web pública + /panel + /superadmin (src/app, src/lib, e2e/)
db/         migraciones SQL numeradas + seed de demo
deploy/     Caddyfile + runbook de producción
docs/       especificación completa (00-16) + openapi.yaml
```

## Documentación

Empezar por [`docs/00-indice.md`](docs/00-indice.md). Atajos:
**estado actual y pendientes** → doc 16 · **API** → [`docs/openapi.yaml`](docs/openapi.yaml)
· **disponibilidad/anti-solape** → doc 02 · **multi-tenant** → docs 08 y 15
· **RGPD** → doc 09.

## Decisiones de arquitectura

- PostgreSQL como única fuente de verdad; horas en **UTC** (zona por sede al presentar).
- Anti-doble-reserva garantizado **en la BD** (restricción de exclusión sobre
  tramos ocupados; compatible con servicios con tiempos muertos, p. ej. tintes).
- Multi-tenant por `account_id` + subdominio en la web + línea de WhatsApp en el
  bot, con **RLS como red de seguridad** (rol de app sin BYPASSRLS).
- Sin ORM ni bundles de auth: SQL parametrizado y JWT HS256 propio, dependencias mínimas.
- Integraciones siempre **opcionales y degradables** (sin credenciales → log).
