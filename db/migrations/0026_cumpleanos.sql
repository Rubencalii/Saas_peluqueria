-- =====================================================================
-- 0026_cumpleanos.sql · Cumpleaños de clientes
--
-- Fecha opcional en la ficha + felicitación automática por WhatsApp
-- (comando app:notifications:birthdays, cron diario). birthday_greeted_on
-- evita felicitar dos veces el mismo año.
-- =====================================================================

ALTER TABLE customer ADD COLUMN birthday DATE;
ALTER TABLE customer ADD COLUMN birthday_greeted_on DATE;

-- Búsqueda diaria por mes/día de cumpleaños.
CREATE INDEX idx_customer_birthday
    ON customer (EXTRACT(MONTH FROM birthday), EXTRACT(DAY FROM birthday))
    WHERE birthday IS NOT NULL;
