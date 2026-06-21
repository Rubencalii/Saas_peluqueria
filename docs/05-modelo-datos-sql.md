# 05 · Modelo de Datos y Esquema SQL

> Esquema orientativo para PostgreSQL. Nombres y tipos son punto de partida; el desarrollador puede ajustarlos.

## 1. Diagrama de entidades (resumen)

```
            ┌──────────┐
            │ location │ (sede)
            └────┬─────┘
                 │ 1..N
   ┌─────────────┼───────────────────┬───────────────┐
   │             │                   │               │
┌──▼───┐   ┌─────▼──────┐      ┌──────▼─────┐   ┌─────▼──────┐
│staff │   │  service   │      │ branding   │   │   user     │
└──┬───┘   └─────┬──────┘      │  (tema)    │   │ (login)    │
   │             │             └────────────┘   └────────────┘
   │  N..M       │ N..M
   │ staff_      │ service_
   │ services    │ locations
   │             │
   │       ┌─────▼──────────┐
   │       │ service_segment│ (tintes con tiempos muertos)
   │       └────────────────┘
   │
┌──▼───────────┐      ┌──────────────┐     ┌───────────────┐
│ appointment  │──────│  customer    │     │ notification  │
└──────┬───────┘ N..1 └──────────────┘     └──────┬────────┘
       │  1..N                                    │
       └──────────────────────────────────────────┘
┌──────────────┐
│ time_block   │ (bloqueos/ausencias por profesional)
└──────────────┘
```

## 2. Esquema SQL (DDL orientativo)

```sql
-- SEDES
CREATE TABLE location (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    slug          TEXT UNIQUE NOT NULL,          -- para URL / subdominio
    address       TEXT,
    phone         TEXT,
    timezone      TEXT NOT NULL DEFAULT 'Europe/Madrid',
    active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- TEMA / BRANDING POR SEDE (diseño propio)
CREATE TABLE branding (
    location_id   BIGINT PRIMARY KEY REFERENCES location(id) ON DELETE CASCADE,
    logo_url      TEXT,
    color_primary TEXT,           -- p.ej. '#1A1A1A'
    color_accent  TEXT,
    font_family   TEXT,
    custom_domain TEXT,           -- opcional: dominio propio de la sede
    extra         JSONB           -- ajustes adicionales del tema
);

-- PROFESIONALES
CREATE TABLE staff (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT,
    phone         TEXT,
    active        BOOLEAN NOT NULL DEFAULT TRUE
);

-- Profesional <-> Sede (un profesional puede estar en varias sedes)
CREATE TABLE staff_location (
    staff_id      BIGINT REFERENCES staff(id) ON DELETE CASCADE,
    location_id   BIGINT REFERENCES location(id) ON DELETE CASCADE,
    PRIMARY KEY (staff_id, location_id)
);

-- HORARIO LABORAL (por profesional, sede y día de la semana; admite turnos partidos)
CREATE TABLE staff_schedule (
    id            BIGSERIAL PRIMARY KEY,
    staff_id      BIGINT REFERENCES staff(id) ON DELETE CASCADE,
    location_id   BIGINT REFERENCES location(id) ON DELETE CASCADE,
    weekday       SMALLINT NOT NULL,        -- 0=lunes … 6=domingo
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL
);

-- SERVICIOS
CREATE TABLE service (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    duration_min  INT NOT NULL,             -- duración total "clásica"
    buffer_min    INT NOT NULL DEFAULT 0,   -- margen tras el servicio
    price         NUMERIC(8,2),
    description   TEXT,
    active        BOOLEAN NOT NULL DEFAULT TRUE
);

-- Servicio disponible (y precio) por sede; permite precio común o específico
CREATE TABLE service_location (
    service_id     BIGINT REFERENCES service(id) ON DELETE CASCADE,
    location_id    BIGINT REFERENCES location(id) ON DELETE CASCADE,
    price_override NUMERIC(8,2),
    PRIMARY KEY (service_id, location_id)
);

-- Qué profesional puede hacer qué servicio
CREATE TABLE staff_service (
    staff_id      BIGINT REFERENCES staff(id) ON DELETE CASCADE,
    service_id    BIGINT REFERENCES service(id) ON DELETE CASCADE,
    PRIMARY KEY (staff_id, service_id)
);

-- SEGMENTOS DE SERVICIO (tintes con tiempo muerto)
CREATE TABLE service_segment (
    id            BIGSERIAL PRIMARY KEY,
    service_id    BIGINT REFERENCES service(id) ON DELETE CASCADE,
    position      SMALLINT NOT NULL,        -- orden del segmento
    minutes       INT NOT NULL,
    busy          BOOLEAN NOT NULL          -- TRUE=ocupa al profesional, FALSE=espera
);

-- CLIENTES (únicos a nivel de cadena por teléfono)
CREATE TABLE customer (
    id              BIGSERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    phone           TEXT UNIQUE NOT NULL,   -- clave de identificación
    email           TEXT,
    wa_consent      BOOLEAN NOT NULL DEFAULT FALSE,
    consent_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- CITAS
CREATE TYPE appointment_status AS ENUM
    ('pendiente','confirmada','cancelada','completada','no_show');
CREATE TYPE booking_channel AS ENUM ('web','whatsapp','manual');

CREATE TABLE appointment (
    id            BIGSERIAL PRIMARY KEY,
    customer_id   BIGINT REFERENCES customer(id),
    staff_id      BIGINT REFERENCES staff(id),
    service_id    BIGINT REFERENCES service(id),
    location_id   BIGINT REFERENCES location(id),
    start_at      TIMESTAMPTZ NOT NULL,     -- en UTC
    end_at        TIMESTAMPTZ NOT NULL,     -- en UTC
    status        appointment_status NOT NULL DEFAULT 'confirmada',
    channel       booking_channel NOT NULL,
    notes         TEXT,
    created_by    BIGINT,                   -- user.id si la creó personal
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- BLOQUEOS (vacaciones, descansos, baja)
CREATE TABLE time_block (
    id            BIGSERIAL PRIMARY KEY,
    staff_id      BIGINT REFERENCES staff(id) ON DELETE CASCADE,
    location_id   BIGINT REFERENCES location(id),
    start_at      TIMESTAMPTZ NOT NULL,
    end_at        TIMESTAMPTZ NOT NULL,
    reason        TEXT
);

-- NOTIFICACIONES
CREATE TYPE notification_type AS ENUM
    ('confirmacion','recordatorio','cambio','seguimiento');
CREATE TYPE notification_status AS ENUM
    ('programada','enviada','fallida');

CREATE TABLE notification (
    id              BIGSERIAL PRIMARY KEY,
    appointment_id  BIGINT REFERENCES appointment(id) ON DELETE CASCADE,
    type            notification_type NOT NULL,
    status          notification_status NOT NULL DEFAULT 'programada',
    template_name   TEXT,
    scheduled_at    TIMESTAMPTZ,
    sent_at         TIMESTAMPTZ
);

-- USUARIOS DEL SISTEMA (login interno)
CREATE TYPE user_role AS ENUM
    ('recepcion','profesional','admin_sede','admin_cadena');

CREATE TABLE app_user (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role          user_role NOT NULL,
    location_id   BIGINT REFERENCES location(id),  -- NULL para admin_cadena
    active        BOOLEAN NOT NULL DEFAULT TRUE
);
```

## 3. Restricción anti-solapamiento (clave)

Para impedir dobles reservas a nivel de base de datos (ver doc 02):

```sql
-- Requiere la extensión btree_gist
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE appointment
  ADD CONSTRAINT no_overlap_per_staff
  EXCLUDE USING gist (
      staff_id WITH =,
      tstzrange(start_at, end_at) WITH &&
  )
  WHERE (status IN ('pendiente','confirmada'));
```
Esto hace que la propia base de datos rechace dos citas activas que se solapen para el mismo profesional, incluso ante peticiones simultáneas de web y WhatsApp.

> Nota: con servicios de tiempos muertos, esta restricción debe aplicarse a los **segmentos ocupados**, no al rango completo. Una opción es materializar los segmentos ocupados en una tabla `appointment_busy_block` y poner la restricción ahí. Decidir según se implemente el doc 02.

## 4. Índices recomendados
- `appointment (staff_id, start_at)` — cálculo de disponibilidad.
- `appointment (location_id, start_at)` — vista de agenda por sede.
- `customer (phone)` — ya único; identificación rápida.
- `notification (status, scheduled_at)` — para el worker de recordatorios.

## 5. Reglas de negocio reflejadas en datos
- El **teléfono** identifica al cliente en toda la cadena (`customer.phone UNIQUE`).
- Las horas se guardan en **UTC** (`TIMESTAMPTZ`) y se presentan en la zona de la sede.
- El estado de la cita gobierna la disponibilidad y los informes.
- El branding está separado de la lógica → permite diseño propio por sede sin tocar el resto.
