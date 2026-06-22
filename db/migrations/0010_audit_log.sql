-- =====================================================================
-- 0010_audit_log.sql · Registro de actividad del panel (doc 09 §6)
--
-- Traza de las acciones de escritura (POST/PATCH/DELETE) sobre `/api/v1/admin`:
-- quién, qué método, qué ruta y con qué resultado. Sirve de auditoría de
-- seguridad y para investigar incidencias. Lo rellena un listener automático.
-- =====================================================================

CREATE TABLE audit_log (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT REFERENCES app_user(id),
    user_email  TEXT,
    method      TEXT NOT NULL,
    path        TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_log_user ON audit_log (user_id, created_at DESC);
CREATE INDEX idx_audit_log_created ON audit_log (created_at DESC);
