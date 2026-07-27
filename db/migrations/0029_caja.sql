-- =====================================================================
-- 0029_caja.sql · Cierre de caja diario
--
-- Hasta ahora se sabía cuánto se había facturado, pero no CÓMO se cobró.
-- Se añade la forma de pago a la cita (se rellena al cobrar, en la pantalla
-- de caja) y una tabla de arqueos: al cerrar el día se guarda el efectivo
-- esperado (lo que dicen las citas) frente al contado en el cajón, para que
-- el descuadre quede registrado en vez de perderse.
--
-- 'bono' y 'regalo' son cobros ya prepagados (bono de sesiones o tarjeta
-- regalo): cuentan como servicio prestado pero NO entran en el efectivo.
-- =====================================================================

CREATE TYPE payment_method AS ENUM ('efectivo', 'tarjeta', 'bono', 'regalo', 'online');

-- NULL = todavía sin cobrar/registrar (es lo que la pantalla de caja resalta).
ALTER TABLE appointment ADD COLUMN payment_method payment_method;

-- Arqueo del día por sede. Uno por (sede, fecha): volver a cerrar lo actualiza.
CREATE TABLE cash_close (
    id            BIGSERIAL PRIMARY KEY,
    account_id    BIGINT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
    location_id   BIGINT NOT NULL REFERENCES location(id) ON DELETE CASCADE,
    business_date DATE NOT NULL,
    expected_cash NUMERIC(10,2) NOT NULL,   -- suma de las citas cobradas en efectivo
    counted_cash  NUMERIC(10,2) NOT NULL,   -- lo contado en el cajón
    notes         TEXT,
    closed_by     BIGINT REFERENCES app_user(id),
    closed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (location_id, business_date)
);
CREATE INDEX idx_cash_close_account ON cash_close (account_id, business_date DESC);

-- Multi-tenant: tabla raíz con account_id → misma política que 0017/0024/0028.
ALTER TABLE cash_close ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON cash_close;
CREATE POLICY tenant_isolation ON cash_close
    USING (account_id = current_setting('app.account_id', true)::bigint)
    WITH CHECK (true);
