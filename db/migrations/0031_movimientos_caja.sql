-- =====================================================================
-- 0031_movimientos_caja.sql · Entradas y salidas de efectivo
--
-- El arqueo (0029) solo contaba ingresos, así que un cajón real nunca
-- cuadraba: falta el fondo de cambio con el que se abre y sobran los pagos
-- en metálico del día (mensajero, material, propinas retiradas).
--
--   entrada = dinero que se mete en el cajón (fondo de cambio, aporte)
--   gasto   = dinero que sale del cajón
--
-- Van por sede y día natural (no por hora): el arqueo es del día, y así un
-- gasto apuntado a las 23:50 no se cuela en el cierre de mañana.
-- =====================================================================

CREATE TYPE cash_movement_kind AS ENUM ('entrada', 'gasto');

CREATE TABLE cash_movement (
    id            BIGSERIAL PRIMARY KEY,
    account_id    BIGINT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
    location_id   BIGINT NOT NULL REFERENCES location(id) ON DELETE CASCADE,
    business_date DATE NOT NULL,
    kind          cash_movement_kind NOT NULL,
    amount        NUMERIC(10,2) NOT NULL CHECK (amount > 0),
    concept       TEXT NOT NULL,
    created_by    BIGINT REFERENCES app_user(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cash_movement_dia ON cash_movement (location_id, business_date);

-- Multi-tenant: tabla raíz con account_id → misma política que 0017/0029.
ALTER TABLE cash_movement ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON cash_movement;
CREATE POLICY tenant_isolation ON cash_movement
    USING (account_id = current_setting('app.account_id', true)::bigint)
    WITH CHECK (true);
