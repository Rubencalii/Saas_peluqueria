-- =====================================================================
-- 0028_comisiones.sql · Comisiones del personal
--
-- El salón define qué porcentaje se lleva cada profesional. Puede fijarse
-- una tarifa general para el profesional (service_id NULL) y afinarla por
-- servicio (service_id = X, que manda sobre la general).
--
-- No se materializa nada por cita: la comisión se calcula en el informe a
-- partir del precio vigente de la cita completada
-- (service_location.price_override o service.price), igual que el informe
-- de ingresos. Así un cambio de tarifa se refleja al recalcular y no hay
-- dos fuentes de verdad que puedan divergir.
-- =====================================================================

CREATE TABLE staff_commission (
    id         BIGSERIAL PRIMARY KEY,
    account_id BIGINT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
    staff_id   BIGINT NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
    service_id BIGINT REFERENCES service(id) ON DELETE CASCADE,  -- NULL = tarifa general
    rate_pct   NUMERIC(5,2) NOT NULL CHECK (rate_pct >= 0 AND rate_pct <= 100),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Una sola tarifa por (profesional, servicio). NULLS NOT DISTINCT hace que la
-- general (service_id NULL) también sea única, sin necesidad de índice parcial.
CREATE UNIQUE INDEX uq_staff_commission ON staff_commission (staff_id, service_id) NULLS NOT DISTINCT;
CREATE INDEX idx_staff_commission_account ON staff_commission (account_id);

-- Multi-tenant: tabla raíz con account_id → misma política que 0017.
ALTER TABLE staff_commission ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON staff_commission;
CREATE POLICY tenant_isolation ON staff_commission
    USING (account_id = current_setting('app.account_id', true)::bigint)
    WITH CHECK (true);
