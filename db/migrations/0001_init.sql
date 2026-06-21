-- =====================================================================
-- 0001_init.sql · Esquema base
-- Sistema de reservas para cadena de peluquerías (multi-sede)
-- Basado en docs/05-modelo-datos-sql.md
-- PostgreSQL 14+
-- =====================================================================

-- Extensiones necesarias
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- restricción anti-solapamiento (0002)
CREATE EXTENSION IF NOT EXISTS pgcrypto;     -- hash de contraseñas en el seed (crypt/bf)

-- ---------------------------------------------------------------------
-- Tipos enumerados
-- ---------------------------------------------------------------------
CREATE TYPE appointment_status AS ENUM
    ('pendiente','confirmada','cancelada','completada','no_show');
CREATE TYPE booking_channel AS ENUM ('web','whatsapp','manual');
CREATE TYPE notification_type AS ENUM
    ('confirmacion','recordatorio','cambio','seguimiento');
CREATE TYPE notification_status AS ENUM ('programada','enviada','fallida');
CREATE TYPE user_role AS ENUM
    ('recepcion','profesional','admin_sede','admin_cadena');

-- ---------------------------------------------------------------------
-- SEDES
-- ---------------------------------------------------------------------
CREATE TABLE location (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    slug          TEXT UNIQUE NOT NULL,            -- para URL / subdominio
    address       TEXT,
    phone         TEXT,
    timezone      TEXT NOT NULL DEFAULT 'Europe/Madrid',
    active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- TEMA / BRANDING POR SEDE (white-label, doc 08)
CREATE TABLE branding (
    location_id   BIGINT PRIMARY KEY REFERENCES location(id) ON DELETE CASCADE,
    logo_url      TEXT,
    color_primary TEXT,
    color_accent  TEXT,
    font_family   TEXT,
    custom_domain TEXT UNIQUE,                     -- opcional: dominio propio
    extra         JSONB
);

-- ---------------------------------------------------------------------
-- PROFESIONALES
-- ---------------------------------------------------------------------
CREATE TABLE staff (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT,
    phone         TEXT,
    active        BOOLEAN NOT NULL DEFAULT TRUE
);

-- Profesional <-> Sede (un profesional puede estar en varias sedes)
CREATE TABLE staff_location (
    staff_id      BIGINT NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    location_id   BIGINT NOT NULL REFERENCES location(id) ON DELETE CASCADE,
    PRIMARY KEY (staff_id, location_id)
);

-- HORARIO LABORAL (por profesional, sede y día; admite turnos partidos)
CREATE TABLE staff_schedule (
    id            BIGSERIAL PRIMARY KEY,
    staff_id      BIGINT NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    location_id   BIGINT NOT NULL REFERENCES location(id) ON DELETE CASCADE,
    weekday       SMALLINT NOT NULL CHECK (weekday BETWEEN 0 AND 6),  -- 0=lunes … 6=domingo
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    CHECK (end_time > start_time)
);

-- ---------------------------------------------------------------------
-- SERVICIOS
-- ---------------------------------------------------------------------
CREATE TABLE service (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    duration_min  INT NOT NULL CHECK (duration_min > 0),  -- duración total "clásica"
    buffer_min    INT NOT NULL DEFAULT 0 CHECK (buffer_min >= 0),
    price         NUMERIC(8,2),
    description   TEXT,
    active        BOOLEAN NOT NULL DEFAULT TRUE
);

-- Servicio (y precio) por sede; permite precio común o específico
CREATE TABLE service_location (
    service_id     BIGINT NOT NULL REFERENCES service(id) ON DELETE CASCADE,
    location_id    BIGINT NOT NULL REFERENCES location(id) ON DELETE CASCADE,
    price_override NUMERIC(8,2),
    PRIMARY KEY (service_id, location_id)
);

-- Qué profesional puede hacer qué servicio
CREATE TABLE staff_service (
    staff_id      BIGINT NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    service_id    BIGINT NOT NULL REFERENCES service(id) ON DELETE CASCADE,
    PRIMARY KEY (staff_id, service_id)
);

-- SEGMENTOS DE SERVICIO (tintes con tiempo muerto, doc 02 §5)
CREATE TABLE service_segment (
    id            BIGSERIAL PRIMARY KEY,
    service_id    BIGINT NOT NULL REFERENCES service(id) ON DELETE CASCADE,
    position      SMALLINT NOT NULL,               -- orden del segmento
    minutes       INT NOT NULL CHECK (minutes > 0),
    busy          BOOLEAN NOT NULL,                -- TRUE=ocupa al profesional, FALSE=espera
    UNIQUE (service_id, position)
);

-- ---------------------------------------------------------------------
-- CLIENTES (únicos a nivel de cadena por teléfono)
-- ---------------------------------------------------------------------
CREATE TABLE customer (
    id              BIGSERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    phone           TEXT UNIQUE NOT NULL,          -- clave de identificación
    email           TEXT,
    wa_consent      BOOLEAN NOT NULL DEFAULT FALSE,
    consent_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- CITAS
-- ---------------------------------------------------------------------
-- start_at/end_at = huella COMPLETA de la cita (incluye buffer y, en
-- servicios segmentados, también los tramos de espera). El bloqueo real
-- del profesional se materializa en appointment_busy_block (ver 0002).
CREATE TABLE appointment (
    id            BIGSERIAL PRIMARY KEY,
    customer_id   BIGINT REFERENCES customer(id),
    staff_id      BIGINT REFERENCES staff(id),     -- NULL sólo mientras "sin preferencia" sin asignar
    service_id    BIGINT NOT NULL REFERENCES service(id),
    location_id   BIGINT NOT NULL REFERENCES location(id),
    start_at      TIMESTAMPTZ NOT NULL,            -- UTC
    end_at        TIMESTAMPTZ NOT NULL,            -- UTC
    status        appointment_status NOT NULL DEFAULT 'confirmada',
    channel       booking_channel NOT NULL,
    notes         TEXT,
    created_by    BIGINT,                          -- app_user.id si la creó personal
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (end_at > start_at)
);

-- Mantener updated_at al día
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_appointment_updated_at
    BEFORE UPDATE ON appointment
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- BLOQUEOS (vacaciones, descansos, baja)
CREATE TABLE time_block (
    id            BIGSERIAL PRIMARY KEY,
    staff_id      BIGINT NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    location_id   BIGINT REFERENCES location(id),
    start_at      TIMESTAMPTZ NOT NULL,
    end_at        TIMESTAMPTZ NOT NULL,
    reason        TEXT,
    CHECK (end_at > start_at)
);

-- ---------------------------------------------------------------------
-- NOTIFICACIONES
-- ---------------------------------------------------------------------
CREATE TABLE notification (
    id              BIGSERIAL PRIMARY KEY,
    appointment_id  BIGINT REFERENCES appointment(id) ON DELETE CASCADE,
    type            notification_type NOT NULL,
    status          notification_status NOT NULL DEFAULT 'programada',
    template_name   TEXT,
    scheduled_at    TIMESTAMPTZ,
    sent_at         TIMESTAMPTZ
);

-- ---------------------------------------------------------------------
-- USUARIOS DEL SISTEMA (login interno)
-- ---------------------------------------------------------------------
CREATE TABLE app_user (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role          user_role NOT NULL,
    location_id   BIGINT REFERENCES location(id),  -- NULL para admin_cadena
    active        BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Índices recomendados (doc 05 §4)
-- ---------------------------------------------------------------------
CREATE INDEX idx_appointment_staff_start    ON appointment (staff_id, start_at);
CREATE INDEX idx_appointment_location_start ON appointment (location_id, start_at);
CREATE INDEX idx_notification_pending       ON notification (status, scheduled_at);
CREATE INDEX idx_time_block_staff_start     ON time_block (staff_id, start_at);
