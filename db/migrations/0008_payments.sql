-- =====================================================================
-- 0008_payments.sql · Depósito / pago online (doc 13 §2.5)
--
-- Un servicio puede requerir un depósito (reembolsable) para reducir no-shows.
-- El cobro se hace con Stripe (PaymentIntent): el cliente paga desde la web y
-- un webhook confirma el pago. La reserva en sí no cambia: el depósito es una
-- capa de cobro/seguimiento desacoplada (cada pago va ligado a una cita).
-- =====================================================================

-- Importe del depósito del servicio (NULL = sin depósito).
ALTER TABLE service ADD COLUMN deposit_amount NUMERIC(8,2);

CREATE TYPE payment_status AS ENUM ('pendiente', 'pagado', 'reembolsado', 'fallido');

CREATE TABLE payment (
    id                       BIGSERIAL PRIMARY KEY,
    appointment_id           BIGINT NOT NULL REFERENCES appointment(id) ON DELETE CASCADE,
    amount                   NUMERIC(8,2) NOT NULL,
    currency                 TEXT NOT NULL DEFAULT 'eur',
    status                   payment_status NOT NULL DEFAULT 'pendiente',
    stripe_payment_intent_id TEXT UNIQUE,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    paid_at                  TIMESTAMPTZ
);

CREATE INDEX idx_payment_appointment ON payment (appointment_id);
