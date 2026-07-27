# 16 · Estado del proyecto y pendientes

> Última actualización: **2026-07-27** · Rama: `main` (todo lo listado como "hecho" está commiteado y en verde)
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
- A fecha de este documento: **124 tests backend / 584 assertions** y **69 tests frontend** en verde, más el smoke E2E. OpenAPI (`docs/openapi.yaml`) al día, incluido `/admin/users`.

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

## 4. Hecho también (2026-07-05, tercera tanda — backlog de producto CERRADO)

- ✅ **Bonos de sesiones** (doc 13): catálogo por cuenta, venta desde la ficha, canje automático e idempotente al completar citas (consume el más próximo a caducar; RLS en `pack`).
- ✅ **Tarjetas regalo**: código legible `GIFT-XXXX-XXXX`, consulta tolerante al tecleo, canje por importe en caja con libro de movimientos (transaccional).
- ✅ **Web pública en 3 idiomas (es/ca/en)**: cookie `lang` + SSR traducido, selector en cabecera, fechas por `Intl`, Stripe Elements en el idioma del visitante; test de paridad de claves entre idiomas.
- ✅ README raíz reescrito al estado real.

## 5. Hecho también (2026-07-08, cuarta tanda)

- ✅ **Agenda**: notas internas por cita e impresión del listado del día (hoja limpia vía CSS de impresión).
- ✅ **Felicitación de cumpleaños** por WhatsApp: fecha en la ficha del cliente y cron `app:birthday:greetings` (idempotente, respeta el consentimiento), añadido al scheduler de producción.
- ✅ **Circuito de valoraciones completo**: petición tras completar la cita, página pública `/valorar` en los 3 idiomas y **reseña de Google** (URL por sede) para las notas altas.
- ✅ **SEO técnico**: `robots.txt`, `sitemap.xml` por host (multi-tenant) y 404 propio en la raíz.
- ✅ **Más cobertura**: E2E del alta manual desde el panel y del viaje completo de *Mi cita* (buscar → reprogramar → cancelar); `AdminCoverageTest` funcional sobre agenda, personal, bloqueos e informes.

## 6. Hecho también (2026-07-27, quinta tanda)

- ✅ **Cierre de caja diario** (migración `0029_caja.sql`): forma de pago por cita (`payment_method`: efectivo/tarjeta/bono/regalo/online) y arqueo por sede y día en `cash_close` (esperado vs contado, con el descuadre registrado). Pantalla **Caja** en el panel: totales por método, las citas del día con su forma de pago editable (las que faltan se destacan), prepagos vendidos y arqueo. Al completar una cita que consume bono se marca como cobrada por bono sola. El efectivo esperado se recalcula siempre en el servidor, con el mismo helper que usa la pantalla.
- ✅ **Histórico de arqueos**: los últimos 30 cierres de la sede en la propia pantalla de Caja (plegado), con el descuadre de cada día y el acumulado. Un descuadre suelto es un despiste; verlos seguidos es lo que dice si hay un problema.
- ✅ **Prepagos en el arqueo** (migración `0030_prepagos_forma_pago.sql`): la venta de un bono o una tarjeta regalo guarda su forma de pago **y la sede** (sin la sede, en una cadena la misma venta aparecería en el arqueo de todas). Se pide al vender —en Tarjetas regalo y en la ficha del cliente— y se puede corregir desde Caja. Con esto el efectivo esperado ya incluye los prepagos cobrados en metálico. Las ventas de un `admin_cadena` sin sede fija quedan sin sede y no cuentan para ningún cajón.
- ✅ **Comisiones del personal** (migración `0028_comisiones.sql`): tarifa general por profesional y excepciones por servicio (la del servicio manda). No se materializa nada por cita: el informe `/admin/reports/commissions` calcula sobre las mismas citas **completadas** que el de ingresos, así que cambiar una tarifa se refleja al recalcular y no hay dos fuentes de verdad. UI: bloque *Comisiones* en la ficha del profesional (Personal) y sección + export CSV con detalle por servicio en Informes.
- ✅ **Cada profesional ve su liquidación**: es el único informe que el rol `profesional` puede consultar, y el backend lo acota a su propia ficha (vínculo por email, el mismo de "Solo mis citas"); sin ficha vinculada responde 403. En el panel de inicio ve *Tus comisiones este mes* con el desglose por servicio.
- ✅ **Registro de migraciones regularizado**: `schema_migration` no tenía anotadas 0023–0027 (se aplicaron a mano en su día) y `--status` mentía. Anotadas como aplicadas; 0028 ya entró por el runner en dev y test.
- ✅ **Agenda: se acabó ver el día equivocado**. Al crear una cita para otro día se lanzaban dos recargas (la del día viejo y la del nuevo) y ganaba la que respondiera la última. Ahora la agenda descarta las respuestas que llegan tarde. Salió al hacer determinista el E2E: su aserción pasaba por casualidad porque el nombre fijo del cliente casaba con citas de ejecuciones anteriores.
- ✅ **Smoke E2E idempotente**: cancela por API las citas que crea. Corre contra la BD de desarrollo, que no se resetea, y cada ejecución dejaba ocupado el mismo lunes hasta agotar la agenda de los servicios que ofrece un solo profesional. Dos ejecuciones seguidas quedan en verde y sin residuos.
- ✅ **Panel por rol de verdad**: la navegación solo declaraba permisos en 5 de 19 entradas, así que un profesional veía Servicios, Personal, Sedes, Informes, Cuenta y Apariencia (y se comía un 403 al entrar), y el inicio le pedía cuatro datos que su rol no puede leer y los pintaba como "—". Los permisos por área viven ahora en `frontend/src/lib/roles.ts` —espejo del `assertRole` de cada controlador, con test— y los usan tanto la navegación como el inicio, que además solo pide lo que el rol puede ver.
- ✅ **CI en verde otra vez**: el test del login del panel mockeaba `@/lib/admin` entero, así que `AdminApiError` quedaba `undefined` y el `catch` de la página reventaba desde que se añadió el 2FA. El mock conserva ahora el módulo real; añadida cobertura del segundo factor (`TOTP_REQUIRED` pide el código y lo reenvía, `TOTP_INVALID` avisa).
- ✅ **E2E con puertos configurables** (`E2E_API_PORT` / `E2E_WEB_PORT`): antes fallaba en seco si algo ocupaba el 8000 o el 3000. El `next dev` de la prueba recibe el `API_BASE` del backend que arranca Playwright. Y en **serie** (`workers: 1`, timeout 90 s): en paralelo, varias pestañas pidiendo rutas que `next dev` aún no había compilado agotaban el timeout con la app sana.
- ✅ **`composer stan` / `composer test`**: PHPStan necesita `--memory-limit=512M` (con el 128M por defecto de PHP el proceso paralelo se cae); CI ya lo pasaba pero el comando documentado para local no.
- ✅ `.gitignore` ignora las salidas de Playwright (`test-results/`, `playwright-report/`).

## 7. Pendiente (todo requiere recursos externos)

1. **MRR real vía Stripe**: los planes no tienen precio local (solo `stripe_price_id`); exige credenciales de Stripe. Mostrar en la consola cuando haya claves.
2. **CD/staging**: CI testea (unit + E2E) pero no despliega; el runbook de `deploy/README.md` es manual. Requiere servidor y secretos.
3. **Uptime externo con alertas** (Sentry captura errores; nadie avisa si el host cae). Servicio tipo UptimeRobot contra `/api/v1/health`.

---

## 8. Cómo verificar (antes de cada commit)

```bash
# BD de desarrollo/test (puerto 5446)
docker compose up -d

# Backend (desde backend/): PHPStan limpio + suite completa
composer stan
composer test

# Frontend (desde frontend/): lint + tests + build
npm run lint && npx vitest run && npx next build

# E2E (opcional, necesita la BD dev; puertos configurables si 8000/3000 están ocupados)
npm run e2e
```

- `composer stan` fija `--memory-limit=512M`: con el límite por defecto de PHP (128M) el proceso paralelo de PHPStan se cae y el resultado sale incompleto.
- Si el 8000 o el 3000 están ocupados: `E2E_API_PORT=8010 E2E_WEB_PORT=3010 npm run e2e` (en PowerShell, `$env:E2E_API_PORT="8010"`).
- Si la BD no responde: el contenedor `peluqueria_db` se para a veces; `docker compose up -d` y esperar `pg_isready`.

## 9. Convenciones del repo

- Commits **sin marca de agua** ni Co-Authored-By; autor `Ruben <rubencorralromero2018@gmail.com>`; push directo a `main` tras verificar.
- Secretos reales **solo** en `.env.local` (gitignorado); los `.env*` commiteados llevan placeholders.
- UI y mensajes en **castellano**; helpers de lógica en `frontend/src/lib/*` puros y con test (Vitest); tests backend contra la BD real con transacción+rollback por test.
- Mensajes de commit: evitar comillas dobles (rompen el here-string de PowerShell al pasar por `git -m`).
