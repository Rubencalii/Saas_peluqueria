-- =====================================================================
-- 0012_loyalty.sql · Fidelización / puntos (doc 13)
--
-- El cliente acumula puntos al completarse sus citas (1 punto por €). El saldo
-- vive en `customer.loyalty_points` y cada movimiento queda en el libro
-- `loyalty_transaction`. Un índice único evita dar puntos dos veces por la
-- misma cita.
-- =====================================================================

ALTER TABLE customer ADD COLUMN loyalty_points INTEGER NOT NULL DEFAULT 0;

CREATE TABLE loyalty_transaction (
    id             BIGSERIAL PRIMARY KEY,
    customer_id    BIGINT NOT NULL REFERENCES customer(id) ON DELETE CASCADE,
    appointment_id BIGINT REFERENCES appointment(id) ON DELETE SET NULL,
    points         INTEGER NOT NULL,             -- positivo = gana, negativo = canjea
    reason         TEXT NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_loyalty_customer ON loyalty_transaction (customer_id, created_at DESC);

-- Una concesión de puntos por cita completada (idempotencia del abono).
CREATE UNIQUE INDEX idx_loyalty_earn_per_appt
    ON loyalty_transaction (appointment_id)
    WHERE reason = 'cita_completada';
