-- =====================================================================
-- 0003_idempotency.sql · Idempotencia en POST /appointments (doc 06 §6)
--
-- Evita reservas duplicadas por doble toque del cliente o reintentos de
-- red: la web/bot envía una clave única por intento de reserva. Si la
-- clave ya existe, la API devuelve la cita ya creada en lugar de crear
-- otra.
-- =====================================================================

CREATE TABLE idempotency_key (
    key            TEXT PRIMARY KEY,
    appointment_id BIGINT NOT NULL REFERENCES appointment(id) ON DELETE CASCADE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
