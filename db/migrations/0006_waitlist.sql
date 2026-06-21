-- =====================================================================
-- 0006_waitlist.sql · Lista de espera (doc 13 §2.4)
--
-- El cliente se apunta a un servicio/sede (y opcionalmente profesional y día)
-- cuando no hay hueco a su gusto. Un proceso periódico (app:waitlist:notify)
-- revisa la disponibilidad y avisa al primero de la lista cuando se libera un
-- hueco (por cancelación, reprogramación o cambio de agenda). Aumenta la
-- ocupación sin trabajo del personal.
-- =====================================================================

CREATE TYPE waitlist_status AS ENUM ('esperando', 'avisado', 'convertido', 'cancelado');

CREATE TABLE waitlist (
    id           BIGSERIAL PRIMARY KEY,
    location_id  BIGINT NOT NULL REFERENCES location(id),
    service_id   BIGINT NOT NULL REFERENCES service(id),
    staff_id     BIGINT REFERENCES staff(id),         -- NULL = sin preferencia
    customer_id  BIGINT NOT NULL REFERENCES customer(id),
    desired_date DATE,                                -- NULL = cualquier día próximo
    status       waitlist_status NOT NULL DEFAULT 'esperando',
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    notified_at  TIMESTAMPTZ
);

-- Para buscar rápido las entradas activas que casan con un hueco liberado.
CREATE INDEX idx_waitlist_match ON waitlist (location_id, service_id, status);

-- Evita duplicados: un cliente no se apunta dos veces al mismo servicio/sede/día
-- mientras siga esperando. (NULL en desired_date se trata como distinto, ok.)
CREATE UNIQUE INDEX idx_waitlist_unique_pending
    ON waitlist (location_id, service_id, customer_id, COALESCE(desired_date, '0001-01-01'))
    WHERE status = 'esperando';
