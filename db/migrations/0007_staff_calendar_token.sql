-- =====================================================================
-- 0007_staff_calendar_token.sql · Feed iCal por profesional (doc 13 §2.6)
--
-- Cada profesional tiene un token secreto con el que puede suscribirse a su
-- agenda (feed .ics de solo lectura) desde Google/Apple Calendar. El token va
-- en la URL pública del feed; rotarlo invalida las suscripciones anteriores.
-- =====================================================================

-- DEFAULT para que las altas nuevas reciban token sin tocar el INSERT.
ALTER TABLE staff ADD COLUMN calendar_token TEXT DEFAULT encode(gen_random_bytes(16), 'hex');

-- Backfill: token aleatorio para los profesionales existentes (pgcrypto).
UPDATE staff SET calendar_token = encode(gen_random_bytes(16), 'hex') WHERE calendar_token IS NULL;

ALTER TABLE staff ALTER COLUMN calendar_token SET NOT NULL;
ALTER TABLE staff ADD CONSTRAINT staff_calendar_token_key UNIQUE (calendar_token);
