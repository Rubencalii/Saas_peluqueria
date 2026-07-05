-- =====================================================================
-- 0024_bonos.sql · Bonos/packs de sesiones (doc 13 §2)
--
-- El salón define bonos ("10 cortes por 90 €") y los vende a clientes
-- desde el panel. Al COMPLETAR una cita del servicio del bono se descuenta
-- una sesión automáticamente (con rastro en pack_redemption; una cita solo
-- puede consumir una sesión aunque se marque completada dos veces).
-- =====================================================================

-- Definición del bono (catálogo de la cuenta).
CREATE TABLE pack (
    id            BIGSERIAL PRIMARY KEY,
    account_id    BIGINT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
    service_id    BIGINT NOT NULL REFERENCES service(id),
    name          TEXT NOT NULL,
    sessions      INTEGER NOT NULL CHECK (sessions > 0),
    price         NUMERIC(10,2) NOT NULL CHECK (price >= 0),
    validity_days INTEGER CHECK (validity_days > 0),  -- NULL = sin caducidad
    active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_pack_account ON pack (account_id) WHERE active;

-- Bono vendido a un cliente (saldo vivo).
CREATE TABLE customer_pack (
    id            BIGSERIAL PRIMARY KEY,
    customer_id   BIGINT NOT NULL REFERENCES customer(id) ON DELETE CASCADE,
    pack_id       BIGINT NOT NULL REFERENCES pack(id),
    sessions_left INTEGER NOT NULL CHECK (sessions_left >= 0),
    expires_at    TIMESTAMPTZ,                        -- NULL = sin caducidad
    sold_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    sold_by       BIGINT REFERENCES app_user(id)      -- trazabilidad de quién lo vendió
);
CREATE INDEX idx_customer_pack_customer ON customer_pack (customer_id);

-- Consumos: una cita solo puede descontar UNA sesión (idempotencia).
CREATE TABLE pack_redemption (
    id               BIGSERIAL PRIMARY KEY,
    customer_pack_id BIGINT NOT NULL REFERENCES customer_pack(id) ON DELETE CASCADE,
    appointment_id   BIGINT NOT NULL UNIQUE REFERENCES appointment(id) ON DELETE CASCADE,
    redeemed_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Multi-tenant: pack es tabla raíz con account_id → misma política que 0017.
-- customer_pack/pack_redemption quedan acotadas por sus FKs (como appointment).
ALTER TABLE pack ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON pack;
CREATE POLICY tenant_isolation ON pack
    USING (account_id = current_setting('app.account_id', true)::bigint)
    WITH CHECK (true);
