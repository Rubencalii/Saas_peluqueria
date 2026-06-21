-- =====================================================================
-- 0002_overlap_protection.sql · Anti-doble-reserva (doc 02 §4, doc 05 §3)
--
-- Decisión de diseño (el doc la dejaba abierta):
--   La restricción de exclusión NO se pone sobre el rango completo de la
--   cita, porque en servicios con tiempos muertos (tintes) el profesional
--   está LIBRE durante el reposo y debe poder atender a otra persona.
--
--   En su lugar materializamos sólo los TRAMOS OCUPADOS de cada cita en
--   appointment_busy_block y aplicamos ahí la restricción. Un trigger
--   recalcula esos tramos a partir de los segmentos del servicio.
--
--   Así la propia base de datos rechaza dos citas activas que solapen
--   para el mismo profesional, aunque la lógica de aplicación falle o
--   lleguen peticiones simultáneas de web y WhatsApp.
-- =====================================================================

-- Tramos en los que el profesional está realmente ocupado.
CREATE TABLE appointment_busy_block (
    id              BIGSERIAL PRIMARY KEY,
    appointment_id  BIGINT NOT NULL REFERENCES appointment(id) ON DELETE CASCADE,
    staff_id        BIGINT NOT NULL REFERENCES staff(id),
    start_at        TIMESTAMPTZ NOT NULL,
    end_at          TIMESTAMPTZ NOT NULL,
    CHECK (end_at > start_at)
);

CREATE INDEX idx_busy_block_appointment ON appointment_busy_block (appointment_id);
CREATE INDEX idx_busy_block_staff_start ON appointment_busy_block (staff_id, start_at);

-- Sólo viven aquí los tramos de citas ACTIVAS (ver trigger), por lo que la
-- restricción no necesita filtro de estado: cualquier solapamiento es real.
-- tstzrange '[)' => citas que se tocan en el extremo (10:45 fin / 10:45 inicio)
-- NO se consideran solapadas.
ALTER TABLE appointment_busy_block
    ADD CONSTRAINT no_overlap_per_staff
    EXCLUDE USING gist (
        staff_id WITH =,
        tstzrange(start_at, end_at) WITH &&
    );

-- ---------------------------------------------------------------------
-- Recalcula los tramos ocupados de una cita a partir de sus segmentos.
--   - Citas no activas (cancelada/completada/no_show) o sin profesional
--     asignado => sin tramos (liberan el hueco).
--   - Servicio sin segmentos => un único tramo [start_at, end_at).
--   - Servicio con segmentos => un tramo por cada segmento busy=TRUE,
--     más el buffer final (el profesional sigue ocupado limpiando).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sync_appointment_busy_blocks()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    seg          RECORD;
    cursor_ts    TIMESTAMPTZ;
    has_segments BOOLEAN;
    buf_min      INT;
BEGIN
    -- Empezar de cero para esta cita
    DELETE FROM appointment_busy_block WHERE appointment_id = NEW.id;

    -- Sólo las citas activas con profesional asignado ocupan agenda
    IF NEW.status NOT IN ('pendiente','confirmada') OR NEW.staff_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT EXISTS (SELECT 1 FROM service_segment WHERE service_id = NEW.service_id)
        INTO has_segments;
    SELECT COALESCE(buffer_min, 0) FROM service WHERE id = NEW.service_id
        INTO buf_min;

    IF NOT has_segments THEN
        INSERT INTO appointment_busy_block (appointment_id, staff_id, start_at, end_at)
        VALUES (NEW.id, NEW.staff_id, NEW.start_at, NEW.end_at);
        RETURN NEW;
    END IF;

    cursor_ts := NEW.start_at;
    FOR seg IN
        SELECT minutes, busy FROM service_segment
        WHERE service_id = NEW.service_id
        ORDER BY position
    LOOP
        IF seg.busy THEN
            INSERT INTO appointment_busy_block (appointment_id, staff_id, start_at, end_at)
            VALUES (NEW.id, NEW.staff_id, cursor_ts,
                    cursor_ts + make_interval(mins => seg.minutes));
        END IF;
        cursor_ts := cursor_ts + make_interval(mins => seg.minutes);
    END LOOP;

    -- El buffer posterior también ocupa al profesional
    IF buf_min > 0 THEN
        INSERT INTO appointment_busy_block (appointment_id, staff_id, start_at, end_at)
        VALUES (NEW.id, NEW.staff_id, cursor_ts,
                cursor_ts + make_interval(mins => buf_min));
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_sync_busy_blocks
    AFTER INSERT OR UPDATE OF status, staff_id, start_at, end_at, service_id
    ON appointment
    FOR EACH ROW EXECUTE FUNCTION sync_appointment_busy_blocks();
