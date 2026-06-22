-- =====================================================================
-- 0013_recurring.sql · Citas recurrentes (doc 13)
--
-- Un cliente habitual repite un servicio cada N semanas (p. ej. corte cada 4).
-- Se guarda la plantilla (día de la semana + hora local + intervalo) y un cron
-- (app:recurring:generate) materializa la próxima cita reutilizando la lógica
-- de reserva (disponibilidad + anti-solape). `last_generated_date` evita
-- duplicar y permite calcular la siguiente ocurrencia.
-- =====================================================================

CREATE TABLE recurring_appointment (
    id                  BIGSERIAL PRIMARY KEY,
    customer_id         BIGINT NOT NULL REFERENCES customer(id) ON DELETE CASCADE,
    location_id         BIGINT NOT NULL REFERENCES location(id),
    service_id          BIGINT NOT NULL REFERENCES service(id),
    staff_id            BIGINT REFERENCES staff(id),       -- NULL = sin preferencia
    weekday             SMALLINT NOT NULL CHECK (weekday BETWEEN 0 AND 6),  -- 0=lun..6=dom
    time_local          TIME NOT NULL,                     -- hora local de la sede
    interval_weeks      SMALLINT NOT NULL DEFAULT 4 CHECK (interval_weeks BETWEEN 1 AND 52),
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    last_generated_date DATE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_recurring_active ON recurring_appointment (active) WHERE active;
