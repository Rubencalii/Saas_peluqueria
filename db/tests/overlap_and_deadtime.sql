-- =====================================================================
-- Prueba manual de la lógica anti-solapamiento y tiempos muertos.
-- Ejecutar sobre una BD con migraciones + seed aplicados, dentro de una
-- transacción que se revierte al final (no deja datos).
--
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=0 -f db/tests/overlap_and_deadtime.sql
-- =====================================================================
BEGIN;

\echo '--- 1) Dos citas que solapan con el MISMO profesional => la 2ª debe fallar ---'

-- Laura (staff 1), Corte hombre/mujer 10:00-10:45
INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel)
VALUES (1, 1, 1, 1, timestamptz '2026-07-01 09:00+00', timestamptz '2026-07-01 09:45+00', 'confirmada', 'web');

-- Solape (09:30) con Laura => EXCLUDE no_overlap_per_staff debe lanzar error
\echo '   (se espera ERROR: conflicting key value / exclusion constraint)'
SAVEPOINT s1;
INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel)
VALUES (2, 1, 2, 1, timestamptz '2026-07-01 09:30+00', timestamptz '2026-07-01 10:00+00', 'confirmada', 'web');
ROLLBACK TO SAVEPOINT s1;

\echo '--- 2) Mismo hueco pero OTRO profesional (Marta) => debe permitirse ---'
INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel)
VALUES (2, 2, 1, 1, timestamptz '2026-07-01 09:30+00', timestamptz '2026-07-01 10:15+00', 'confirmada', 'web');

\echo '--- 3) Tinte (segmentado) con Laura 11:00 => sólo tramos 11:00-11:20 y 11:55-12:10 ocupan ---'
INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel)
VALUES (1, 1, 3, 1, timestamptz '2026-07-01 11:00+00', timestamptz '2026-07-01 12:10+00', 'confirmada', 'web');

SELECT a.id AS cita, b.start_at, b.end_at
FROM appointment a JOIN appointment_busy_block b ON b.appointment_id = a.id
WHERE a.staff_id = 1 AND a.start_at = timestamptz '2026-07-01 11:00+00'
ORDER BY b.start_at;

\echo '--- 4) Otra cita corta DURANTE el reposo del tinte (11:25-11:50) => debe permitirse ---'
INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel)
VALUES (2, 1, 2, 1, timestamptz '2026-07-01 11:25+00', timestamptz '2026-07-01 11:50+00', 'confirmada', 'web');

\echo '--- 5) Cita que pisa el tramo ACTIVO del tinte (11:10) => debe fallar ---'
\echo '   (se espera ERROR: exclusion constraint)'
SAVEPOINT s5;
INSERT INTO appointment (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel)
VALUES (2, 1, 2, 1, timestamptz '2026-07-01 11:10+00', timestamptz '2026-07-01 11:30+00', 'confirmada', 'web');
ROLLBACK TO SAVEPOINT s5;

\echo '--- 6) Cancelar libera el hueco: sus busy_blocks desaparecen ---'
UPDATE appointment SET status = 'cancelada'
WHERE staff_id = 1 AND start_at = timestamptz '2026-07-01 09:00+00';

SELECT count(*) AS bloques_de_la_cancelada
FROM appointment a JOIN appointment_busy_block b ON b.appointment_id = a.id
WHERE a.staff_id = 1 AND a.start_at = timestamptz '2026-07-01 09:00+00';  -- esperado: 0

ROLLBACK;
\echo '--- Fin (transacción revertida, sin cambios persistidos) ---'
