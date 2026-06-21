-- =====================================================================
-- seed.sql · Datos de demostración
-- Ejecutar DESPUÉS de las migraciones 0001 y 0002.
-- Idempotente: limpia y recrea los datos de ejemplo.
-- =====================================================================

TRUNCATE TABLE
    appointment_busy_block, notification, appointment, time_block,
    staff_schedule, staff_service, staff_location, service_segment,
    service_location, service, staff, branding, app_user, customer, location
RESTART IDENTITY CASCADE;

-- ---------------------------------------------------------------------
-- Sedes + branding (white-label)
-- ---------------------------------------------------------------------
INSERT INTO location (id, name, slug, address, phone, timezone) VALUES
    (1, 'Salón Centro', 'centro', 'C/ Mayor 1, Madrid', '+34910000001', 'Europe/Madrid'),
    (2, 'Salón Norte',  'norte',  'Av. del Norte 22, Madrid', '+34910000002', 'Europe/Madrid');

INSERT INTO branding (location_id, logo_url, color_primary, color_accent, font_family) VALUES
    (1, '/brands/centro/logo.svg', '#1A1A1A', '#C8A86B', 'Inter, sans-serif'),
    (2, '/brands/norte/logo.svg',  '#0E5C4A', '#E8B04B', 'Poppins, sans-serif');

-- ---------------------------------------------------------------------
-- Profesionales y asignación a sedes
-- ---------------------------------------------------------------------
INSERT INTO staff (id, name, email, phone) VALUES
    (1, 'Laura',  'laura@salon.es',  '+34600000001'),
    (2, 'Marta',  'marta@salon.es',  '+34600000002'),
    (3, 'Carlos', 'carlos@salon.es', '+34600000003');

INSERT INTO staff_location (staff_id, location_id) VALUES
    (1, 1), (2, 1), (3, 1),   -- los tres en Centro
    (1, 2);                    -- Laura también en Norte

-- ---------------------------------------------------------------------
-- Servicios
-- ---------------------------------------------------------------------
INSERT INTO service (id, name, duration_min, buffer_min, price, description) VALUES
    (1, 'Corte mujer',  45, 0, 18.00, 'Lavado, corte y peinado'),
    (2, 'Corte hombre', 30, 0, 14.00, 'Corte y arreglo'),
    (3, 'Tinte',        70, 0, 35.00, 'Tinte completo con tiempos de reposo'),
    (4, 'Peinado',      30, 0, 15.00, 'Peinado y acabado');

-- Tinte modelado por segmentos (doc 02 §5): el profesional queda LIBRE
-- durante los 35 min de reposo y puede atender otra cita corta.
INSERT INTO service_segment (service_id, position, minutes, busy) VALUES
    (3, 1, 20, TRUE),    -- aplicación  (ocupa)
    (3, 2, 35, FALSE),   -- reposo      (libre)
    (3, 3, 15, TRUE);    -- lavado/peinado (ocupa)

-- Todos los servicios en ambas sedes (precio común; Norte sube el tinte)
INSERT INTO service_location (service_id, location_id, price_override) VALUES
    (1, 1, NULL), (2, 1, NULL), (3, 1, NULL), (4, 1, NULL),
    (1, 2, NULL), (2, 2, NULL), (3, 2, 39.00), (4, 2, NULL);

-- Qué hace cada profesional
INSERT INTO staff_service (staff_id, service_id) VALUES
    (1, 1), (1, 3), (1, 4),         -- Laura: corte mujer, tinte, peinado
    (2, 1), (2, 3), (2, 4),         -- Marta: corte mujer, tinte, peinado
    (3, 2), (3, 1);                 -- Carlos: corte hombre, corte mujer

-- ---------------------------------------------------------------------
-- Horarios laborales (L-V con turno partido; sábado mañana) en Centro
-- weekday: 0=lunes … 6=domingo
-- ---------------------------------------------------------------------
INSERT INTO staff_schedule (staff_id, location_id, weekday, start_time, end_time)
SELECT s.staff_id, 1, d.weekday, t.start_time, t.end_time
FROM (VALUES (1),(2),(3)) AS s(staff_id)
CROSS JOIN (VALUES (0),(1),(2),(3),(4)) AS d(weekday)
CROSS JOIN (VALUES ('09:00'::time,'14:00'::time), ('16:00'::time,'20:00'::time)) AS t(start_time,end_time);

-- Sábado de mañana, todos en Centro
INSERT INTO staff_schedule (staff_id, location_id, weekday, start_time, end_time) VALUES
    (1, 1, 5, '09:00', '14:00'),
    (2, 1, 5, '09:00', '14:00'),
    (3, 1, 5, '09:00', '14:00');

-- Laura en Norte los lunes y miércoles (mañana)
INSERT INTO staff_schedule (staff_id, location_id, weekday, start_time, end_time) VALUES
    (1, 2, 0, '10:00', '14:00'),
    (1, 2, 2, '10:00', '14:00');

-- ---------------------------------------------------------------------
-- Clientes
-- ---------------------------------------------------------------------
INSERT INTO customer (id, name, phone, email, wa_consent, consent_at) VALUES
    (1, 'María García', '+34611111111', 'maria@example.com', TRUE, now()),
    (2, 'Juan Pérez',   '+34622222222', NULL,                FALSE, NULL);

-- ---------------------------------------------------------------------
-- Usuarios del panel (contraseñas hasheadas con bcrypt vía pgcrypto)
-- admin de cadena:  admin@salon.es  / admin1234
-- recepción Centro: recepcion@salon.es / recepcion1234
-- ---------------------------------------------------------------------
INSERT INTO app_user (name, email, password_hash, role, location_id) VALUES
    ('Admin Cadena', 'admin@salon.es',     crypt('admin1234', gen_salt('bf')),     'admin_cadena', NULL),
    ('Recepción',    'recepcion@salon.es', crypt('recepcion1234', gen_salt('bf')), 'recepcion',    1);

-- ---------------------------------------------------------------------
-- Cita de ejemplo (verifica el trigger de tramos ocupados)
-- Corte mujer con Laura en Centro, 45 min => un único busy_block.
-- ---------------------------------------------------------------------
INSERT INTO appointment
    (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel, public_code)
VALUES
    (1, 1, 1, 1,
     date_trunc('day', now()) + interval '1 day' + interval '10 hours',  -- mañana 10:00 local≈UTC
     date_trunc('day', now()) + interval '1 day' + interval '10 hours 45 minutes',
     'confirmada', 'web', encode(gen_random_bytes(8), 'hex'));

-- ---------------------------------------------------------------------
-- Sincronizar las secuencias BIGSERIAL: las filas anteriores se
-- insertaron con id explícito, así que la secuencia sigue en 1 y el
-- siguiente INSERT sin id colisionaría (duplicate key). Avanzamos cada
-- secuencia al máximo id existente.
-- ---------------------------------------------------------------------
SELECT setval(pg_get_serial_sequence('location', 'id'),       (SELECT COALESCE(MAX(id), 1) FROM location));
SELECT setval(pg_get_serial_sequence('staff', 'id'),          (SELECT COALESCE(MAX(id), 1) FROM staff));
SELECT setval(pg_get_serial_sequence('staff_schedule', 'id'), (SELECT COALESCE(MAX(id), 1) FROM staff_schedule));
SELECT setval(pg_get_serial_sequence('service', 'id'),        (SELECT COALESCE(MAX(id), 1) FROM service));
SELECT setval(pg_get_serial_sequence('service_segment', 'id'),(SELECT COALESCE(MAX(id), 1) FROM service_segment));
SELECT setval(pg_get_serial_sequence('customer', 'id'),       (SELECT COALESCE(MAX(id), 1) FROM customer));
SELECT setval(pg_get_serial_sequence('app_user', 'id'),       (SELECT COALESCE(MAX(id), 1) FROM app_user));
SELECT setval(pg_get_serial_sequence('appointment', 'id'),    (SELECT COALESCE(MAX(id), 1) FROM appointment));
