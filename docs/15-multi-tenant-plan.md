# 15 · Plan: Multi-tenant + Suscripciones (SaaS multi-salón)

> **Estado: diseño (no implementado).** Hoy el sistema es **mono-cadena**: un
> único negocio con varias sedes. Para venderlo como SaaS a **muchos salones
> independientes** hay que añadir aislamiento por inquilino (tenant) y
> facturación del propio software. Es un cambio arquitectónico que toca casi
> todas las tablas y consultas; este documento es el plan para hacerlo por
> fases sin romper lo que ya funciona.

## 1. Objetivo

- **Tenant = una cuenta de negocio** (un salón o cadena) con sus sedes,
  usuarios, clientes, servicios, citas, etc., **aislados** de los demás.
- **Suscripción**: cada tenant paga por usar el software (planes con límites).
  *Ojo:* esto es distinto del Stripe ya implementado, que cobra **depósitos de
  cita al cliente final**. La facturación del SaaS es el salón pagándonos a
  nosotros → otra integración de Stripe (Billing/Subscriptions).

## 2. Estrategia de aislamiento (decisión)

| Opción | Aislamiento | Coste/complejidad | Veredicto |
|--------|-------------|-------------------|-----------|
| BD por tenant | Máximo | Alto (N bases, migraciones ×N) | ❌ sobredimensionado al inicio |
| Esquema por tenant | Alto | Medio-alto | ❌ complica el runner de migraciones |
| **Fila con `tenant_id` (shared DB)** | Bueno con disciplina | **Bajo** | ✅ **recomendado** |

**Recomendación:** *shared database, row-level* — añadir `tenant_id` a las
tablas raíz y filtrar **siempre** por él. Encaja con el modelo actual (Doctrine
DBAL, SQL plano) y con el runner de migraciones. Cuando un tenant grande lo
justifique, se puede mover a su propia BD sin cambiar el código (misma forma).

## 3. Cambios en el modelo de datos

Nueva tabla raíz:

```sql
CREATE TABLE account (              -- el tenant
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    slug          TEXT UNIQUE NOT NULL,     -- subdominio: <slug>.reservas.app
    status        TEXT NOT NULL DEFAULT 'trial',  -- trial|active|suspended|cancelled
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Añadir `account_id BIGINT NOT NULL REFERENCES account(id)` a las tablas raíz:
`location`, `app_user`, `service`, `staff`, `customer` (y por herencia, vía sus
FKs, quedan acotadas `appointment`, `waitlist`, `payment`, `review`,
`recurring_appointment`, `conversation`, `notification`, `audit_log`,
`loyalty_transaction`, `password_reset`).

**Unicidades que cambian** (de globales a por-tenant):
- `customer.phone` → `UNIQUE (account_id, phone)` (un mismo móvil puede ser
  cliente de dos salones distintos).
- `location.slug` → `UNIQUE (account_id, slug)`.
- `app_user.email` → `UNIQUE (account_id, email)` (o global si el login es por
  email único; decisión — ver §8).
- `staff.calendar_token` se mantiene global (es secreto, no colisiona).

## 4. Resolución del tenant y scoping de consultas

- **Panel (autenticado):** el `account_id` se añade como *claim* en el JWT al
  hacer login; un `TenantContext` (servicio request-scoped) lo expone. **Todas**
  las consultas del panel añaden `WHERE account_id = :tenant`. La autorización
  por sede/rol sigue, pero **dentro** del tenant.
- **Público (web/bot):** el tenant se resuelve por **dominio/subdominio**
  (`centro.misalon.app`) o por un prefijo de ruta/slug. El bot de WhatsApp
  resuelve el tenant por el `phone_number_id` de Meta (cada salón tiene su línea).
- **Patrón técnico:** introducir un `TenantContext` + un helper de repositorio
  que inyecte el filtro, para no repetir `WHERE account_id` a mano y evitar
  fugas. Alternativa robusta: **Row-Level Security (RLS) de PostgreSQL**
  (`SET app.tenant_id` por conexión + políticas `USING (account_id = ...)`),
  que garantiza el aislamiento a nivel de BD aunque una query se descuide.
  **Recomendado: RLS** como red de seguridad, además del filtro en SQL.

## 5. Suscripciones / facturación del SaaS

```sql
CREATE TABLE plan (                 -- catálogo de planes
    code TEXT PRIMARY KEY,          -- 'free' | 'pro' | 'cadena'
    name TEXT NOT NULL,
    max_locations INTEGER, max_staff INTEGER, max_appointments_month INTEGER,
    stripe_price_id TEXT
);
CREATE TABLE subscription (
    account_id  BIGINT PRIMARY KEY REFERENCES account(id),
    plan_code   TEXT NOT NULL REFERENCES plan(code),
    status      TEXT NOT NULL,      -- trialing|active|past_due|canceled
    stripe_customer_id TEXT, stripe_subscription_id TEXT,
    current_period_end TIMESTAMPTZ
);
```

- **Stripe Billing** (Checkout + Customer Portal) para alta/cambio/baja de plan,
  con su propio webhook (`customer.subscription.*`, `invoice.*`) → actualiza
  `subscription.status`. Reutiliza el patrón del webhook de depósitos pero en
  otro endpoint y con su clave/segredo.
- **Límites de plan**: un guard que rechaza crear sede/usuario/cita por encima
  del plan (429/402). `account.status = suspended` (impago) → solo lectura.

## 6. Onboarding (alta de un salón)

`POST /api/v1/signup` (público): crea `account` (status `trial`) + primer
`app_user` (`admin_cadena`) + primera `location`, e inicia la suscripción en
plan `free`/trial. Email de bienvenida (canal de email ya existe).

## 7. Plan de migración por fases (sin romper lo actual)

| Fase | Qué | Riesgo |
|------|-----|--------|
| **1. Cimientos** | Migración: `account`, `plan`, `subscription`. Añadir `account_id` **nullable** a las tablas raíz; crear un *tenant por defecto* y backfill de todo lo existente; luego `NOT NULL`. | Bajo (aditivo) |
| **2. Scoping panel** | `account_id` en el JWT + `TenantContext`; filtrar todas las consultas del panel; cambiar unicidades a por-tenant. Tests de aislamiento (un tenant no ve datos de otro). | **Alto** (toca todas las queries) |
| **3. Público multi-tenant** | Resolución por dominio/slug en web; por `phone_number_id` en el bot. | Medio |
| **4. RLS** | Políticas Row-Level Security como red de seguridad. | Medio |
| **5. Billing** | Stripe Billing + webhook + límites de plan + estados de cuenta. | Medio |
| **6. Onboarding** | `signup`, trial, email de bienvenida, portal de cliente de Stripe. | Bajo |

Cada fase es un PR independiente con su batería de tests. La fase 2 es la
crítica: conviene apoyarla en **RLS (fase 4 adelantada)** para que un descuido
no provoque fuga de datos entre salones.

## 8. Decisiones abiertas (requieren producto)

1. **Login**: ¿email único global (un usuario, varios tenants) o por-tenant
   (mismo email en salones distintos)? Afecta a `app_user` y al flujo de login.
2. **Planes y precios**: ¿qué límites por plan? ¿hay plan gratis?
3. **Dominio**: ¿subdominio (`salon.reservas.app`), dominio propio por salón, o
   slug en la ruta? (el modelo ya guarda `branding.custom_domain`).
4. **WhatsApp**: ¿una línea de Meta por salón (recomendado para aislamiento) o
   compartida? Determina cómo resuelve el tenant el bot.
5. **Migración de datos actuales**: la cadena existente pasa a ser el primer
   tenant.

## 9. Recomendación

Es un proyecto de varias iteraciones, no un cambio de una tanda. Si se aprueba,
**empezar por la Fase 1** (cimientos, aditiva y de bajo riesgo) y la **Fase 4
(RLS)** antes de la Fase 2, para tener el aislamiento garantizado por la BD
antes de reescribir las consultas. Mientras tanto, el producto **mono-cadena
actual es plenamente funcional** para un primer cliente.
