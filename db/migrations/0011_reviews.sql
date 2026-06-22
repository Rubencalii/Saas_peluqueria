-- =====================================================================
-- 0011_reviews.sql · Valoración post-cita (doc 13 §2, "Valoración post-cita")
--
-- El cliente puntúa (1–5) y comenta su cita tras la visita. Una valoración por
-- cita (UNIQUE). Va ligada a la cita, de la que se derivan profesional/servicio/
-- sede para los agregados (nota media por profesional, etc.).
-- =====================================================================

CREATE TABLE review (
    id             BIGSERIAL PRIMARY KEY,
    appointment_id BIGINT NOT NULL UNIQUE REFERENCES appointment(id) ON DELETE CASCADE,
    rating         SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment        TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
