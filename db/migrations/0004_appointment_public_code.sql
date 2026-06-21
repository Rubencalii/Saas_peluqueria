-- =====================================================================
-- 0004_appointment_public_code.sql · Verificación ligera del cliente
--
-- Los endpoints públicos de gestión de cita (lookup / reschedule / cancel,
-- doc 06 §2) no tienen login. Para identificar al cliente sin cuenta
-- (doc 06: "teléfono + código" o "enlace firmado") damos a cada cita un
-- código aleatorio de un solo uso que se entrega en la confirmación
-- (web/WhatsApp). Quien posee el código + el teléfono puede consultar,
-- reprogramar o cancelar esa cita; así evitamos además enumerar ids.
-- =====================================================================

ALTER TABLE appointment
    ADD COLUMN public_code TEXT;

-- Códigos para las citas ya existentes (datos de demo/seed).
UPDATE appointment
   SET public_code = encode(gen_random_bytes(8), 'hex')
 WHERE public_code IS NULL;

CREATE UNIQUE INDEX idx_appointment_public_code ON appointment (public_code);
