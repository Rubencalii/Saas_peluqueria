-- =====================================================================
-- 0014_multitenant_foundations.sql · Multi-tenant Fase 1 (doc 15)
--
-- CIMIENTOS, 100% aditivos: crea las tablas de cuenta/plan/suscripción, una
-- cuenta principal con los datos actuales, y añade `account_id` a las tablas
-- raíz CON DEFAULT a la cuenta principal, de modo que el código actual sigue
-- funcionando sin cambios (los inserts existentes heredan la cuenta principal).
-- El scoping por tenant en consultas/auth llega en la Fase 2.
-- =====================================================================

CREATE TABLE account (
    id         BIGSERIAL PRIMARY KEY,
    name       TEXT NOT NULL,
    slug       TEXT UNIQUE NOT NULL,
    status     TEXT NOT NULL DEFAULT 'active',   -- trial|active|suspended|cancelled
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE plan (
    code                   TEXT PRIMARY KEY,      -- free|pro|cadena
    name                   TEXT NOT NULL,
    max_locations          INTEGER,               -- NULL = ilimitado
    max_staff              INTEGER,
    max_appointments_month INTEGER,
    stripe_price_id        TEXT
);

CREATE TABLE subscription (
    account_id             BIGINT PRIMARY KEY REFERENCES account(id) ON DELETE CASCADE,
    plan_code              TEXT NOT NULL REFERENCES plan(code),
    status                 TEXT NOT NULL DEFAULT 'active',  -- trialing|active|past_due|canceled
    stripe_customer_id     TEXT,
    stripe_subscription_id TEXT,
    current_period_end     TIMESTAMPTZ
);

INSERT INTO plan (code, name, max_locations, max_staff, max_appointments_month) VALUES
    ('free',   'Gratis',  1,    3,    100),
    ('pro',    'Pro',     3,    15,   NULL),
    ('cadena', 'Cadena',  NULL, NULL, NULL);

-- Cuenta principal = el negocio actual. Es la primera fila → id = 1.
INSERT INTO account (name, slug, status) VALUES ('Cuenta principal', 'principal', 'active');
INSERT INTO subscription (account_id, plan_code, status)
    SELECT id, 'pro', 'active' FROM account WHERE slug = 'principal';

-- account_id en las tablas raíz, NOT NULL con DEFAULT a la cuenta principal (id 1).
-- El DEFAULT mantiene compatibles todos los inserts actuales (Fase 1 no toca código).
ALTER TABLE location ADD COLUMN account_id BIGINT NOT NULL DEFAULT 1 REFERENCES account(id);
ALTER TABLE app_user ADD COLUMN account_id BIGINT NOT NULL DEFAULT 1 REFERENCES account(id);
ALTER TABLE service  ADD COLUMN account_id BIGINT NOT NULL DEFAULT 1 REFERENCES account(id);
ALTER TABLE staff    ADD COLUMN account_id BIGINT NOT NULL DEFAULT 1 REFERENCES account(id);
ALTER TABLE customer ADD COLUMN account_id BIGINT NOT NULL DEFAULT 1 REFERENCES account(id);

CREATE INDEX idx_location_account ON location (account_id);
CREATE INDEX idx_app_user_account ON app_user (account_id);
CREATE INDEX idx_service_account  ON service (account_id);
CREATE INDEX idx_staff_account    ON staff (account_id);
CREATE INDEX idx_customer_account ON customer (account_id);
