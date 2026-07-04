# 16 · Estado del proyecto y pendientes

> Última actualización: **2026-07-04** · Rama: `main` (todo lo listado como "hecho" está commiteado y en verde)
> Objetivo del documento: retomar el trabajo rápido sabiendo **por dónde vamos y lo que queda**.

---

## 1. Hecho (funcional y verificado)

### Núcleo de reservas
- Motor de disponibilidad por profesional/día (grid de 15 min, antelación mínima, tiempos muertos de tintes, márgenes). `AvailabilityService`.
- Anti-doble-reserva garantizado por la BD (trigger de tramos ocupados + restricción `EXCLUDE`); condición de carrera → `409 SLOT_TAKEN`.
- Reserva web pública, idempotencia por clave, gestión self-service del cliente (`/mi-cita`: consultar, reprogramar con rollback seguro, cancelar con ventana de antelación).
- Citas recurrentes: plantilla por cliente + cron `app:recurring:generate` (usa la misma validación de hueco).
- Lista de espera: alta pública, aviso automático al liberarse hueco (cron `app:waitlist:notify`, testeado), panel con paginación.

### Panel del salón (frontend Next.js + API Symfony)
- **Agenda**: vista día/semana, alta manual de cita, "Próximo hueco por profesional" con botón **Reservar** (formulario prerrellenado con hueco sugerido), reservar para **cliente existente** (búsqueda y enlace sin duplicar ni revocar consentimiento), filtro **"Solo mis citas"** para el rol profesional (vía `staff_id` en `/me`, vinculado por email).
- **Clientes**: búsqueda, filtro por consentimiento WhatsApp, próxima cita en ficha, edición y derechos RGPD (exportar / anonimizar).
- **WhatsApp**: bandeja de atención humana con paginación, responder y devolver el control al bot (cierra la ficha al resolver).
- **Informes**: KPIs (ingresos, no-show, retención, valoraciones), por canal, ocupación, horas punta; **export CSV** completo y protegido contra inyección de fórmulas.
- **Usuarios del panel** (endpoint nuevo + UI): invitar con rol/sede, cambiar rol/sede, desactivar (revoca sesiones al instante), autoprotección del propio admin. Solo `admin_cadena`.
- **Auditoría** (UI nueva): registro de actividad paginado, solo `admin_cadena`.
- **Recurrentes** (UI nueva): alta/listado/baja por sede.
- Resto: servicios (segmentos/tiempos muertos, precios por sede), personal + horarios, bloqueos/ausencias, sedes, valoraciones, cuenta/suscripción (Stripe checkout + portal), apariencia (branding white-label), superadmin de plataforma.
- La navegación del panel filtra entradas por rol.

### Acceso al SaaS
- **Alta pública** `/alta`: crea cuenta (trial) + primera sede + admin y entra directo al panel; slug autogenerado y validado como el backend.
- Login, **recuperar contraseña** (`/recuperar-contrasena` + `/restablecer-contrasena`), verificación de email con reenvío.

### WhatsApp (bot)
- Webhook con firma fail-closed (testeado), bot de reservas guiado por estados, dedupe de mensajes de Meta, notificaciones (confirmación/recordatorio/cambio), recordatorio de retorno. Sin credenciales degrada a log (dev/test).

### Seguridad / RGPD / mantenimiento
- CSP y cabeceras de seguridad (Next) + cabeceras API (testeadas). Rate limiting en endpoints públicos y signup. RLS multi-tenant en BD. JWT con revocación por `token_version`. Corte del panel con secreto inseguro en host no local. Export CSV endurecido.
- Cron de purga `app:maintenance:purge`: tokens de reset usados/caducados, auditoría > 365 días, dedupe WhatsApp > 30 días, idempotencia > 30 días (configurable, `--dry-run`, testeado).

### Calidad
- **CI (GitHub Actions)**: backend (php -l, PHPStan nivel 5 limpio, PHPUnit con Postgres real) + frontend (ESLint, Vitest, `next build`).
- A fecha de este documento: **100 tests backend / 364 assertions** y **48 tests frontend** en verde. OpenAPI (`docs/openapi.yaml`) al día, incluido `/admin/users`.

---

## 2. Añadido después de la primera versión de este doc (ya en `main`)

- ✅ **Informes comparativos**: cada KPI muestra su variación vs el periodo anterior (`previousRange`/`pctDelta`/`ppDelta` con tests).
- ✅ **Smoke E2E con Playwright** (`npm run e2e`, 2/2 en verde local): reserva pública de punta a punta y login del panel + agenda. Arranca backend (PHP embebido) y `next dev` solo; requiere la BD dev (`docker compose up -d`).
- ✅ **Despliegue de producción**: `backend/Dockerfile` (Apache+PHP 8.5, opcache, imagen verificada), `frontend/Dockerfile` (Next standalone, imagen verificada), `docker-compose.prod.yml` (db, backend, scheduler de crons, frontend, Caddy con TLS, backup diario `pg_dump` con retención) y runbook en `deploy/README.md` (migraciones con `app:db:migrate`, restauración probada de backups).

---

## 3. Hecho también (2026-07-04, segunda tanda)

- ✅ **Hyper diseño**: sistema visual elevado (tipografía display, botones con degradado, fade-up/pop-in, skeletons con brillo, reduced-motion) y **editor de apariencia** con 8 paletas, hex editable, avisos de contraste WCAG y prueba en vivo en todo el panel.
- ✅ **Sentry** back y front (túnel same-origin `/monitoring`, CSP intacta; desactivado sin DSN).
- ✅ **Consola de plataforma** con identidad propia (`[data-console]`) y funcionalidades de operador: ficha de cuenta (contactos, sedes, suscripción/Stripe, actividad), **impersonación auditada**, aviso al cambiar plan gestionado por Stripe, altas/semana y buscador. Auditoría ampliada a `/api/v1/superadmin`.
- ✅ **Sesión deslizante** (renueva el token antes de caducar), **2FA TOTP** (RFC 6238 propio, alta en dos pasos, baja protegida; página `/panel/seguridad`), **PWA** del panel (manifest + iconos), **E2E en CI** (tercer job), suite **sin deprecaciones**.
- ✅ Producto: **evolución mensual** en informes (12 meses), **convertir lista de espera** (marcar convertida + crear cita prerrellenada por URL), **export CSV de clientes** (filtro de consentimiento) y **Open Graph** por salón.

## 4. Pendiente

1. **MRR real vía Stripe**: los planes no tienen precio local (solo `stripe_price_id`); calcularlo exige consultar la API de Stripe con credenciales. Mostrar en la consola cuando haya claves.
2. **CD/staging**: CI testea (unit + E2E) pero no despliega; el runbook de `deploy/README.md` es manual. Requiere servidor y secretos.
3. **Uptime externo con alertas** (Sentry captura errores; nadie avisa si el host cae). Servicio externo tipo UptimeRobot contra `/api/v1/health`.
4. **Backlog de producto** (sin compromiso, doc 13): bonos/packs y tarjetas regalo, multi-idioma (ca/en), series temporales más ricas, app del profesional.

---

## 5. Cómo verificar (antes de cada commit)

```bash
# BD de desarrollo/test (puerto 5446)
docker compose up -d

# Backend (desde backend/): PHPStan limpio + suite completa
vendor/bin/phpstan analyse --no-progress
php bin/phpunit

# Frontend (desde frontend/): lint + tests + build
npm run lint && npx vitest run && npx next build
```

- Las 2 deprecaciones de PHPUnit (`setNestTransactionsWithSavepoints` de Doctrine DBAL) son **preexistentes y conocidas**; la suite se considera verde con ellas.
- Si la BD no responde: el contenedor `peluqueria_db` se para a veces; `docker compose up -d` y esperar `pg_isready`.

## 6. Convenciones del repo

- Commits **sin marca de agua** ni Co-Authored-By; autor `Ruben <rubencorralromero2018@gmail.com>`; push directo a `main` tras verificar.
- Secretos reales **solo** en `.env.local` (gitignorado); los `.env*` commiteados llevan placeholders.
- UI y mensajes en **castellano**; helpers de lógica en `frontend/src/lib/*` puros y con test (Vitest); tests backend contra la BD real con transacción+rollback por test.
- Mensajes de commit: evitar comillas dobles (rompen el here-string de PowerShell al pasar por `git -m`).
