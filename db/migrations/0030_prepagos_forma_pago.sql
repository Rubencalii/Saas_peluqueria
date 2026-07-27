-- =====================================================================
-- 0030_prepagos_forma_pago.sql · Forma de pago y sede de los prepagos
--
-- Completa el cierre de caja (0029): hasta ahora la venta de un bono o de
-- una tarjeta regalo entraba por caja pero no se guardaba CÓMO se cobró, así
-- que el efectivo esperado se quedaba corto y había que cuadrar a ojo.
--
-- También se guarda en qué sede se vendió: sin eso, en una cadena la misma
-- venta aparecería en el arqueo de todas las sedes. Se rellena con la sede
-- del usuario que vende; queda NULL si vende un admin_cadena sin sede fija,
-- y entonces no cuenta para el cajón de ninguna (no hay a cuál asignarla).
--
-- payment_method NULL = venta anterior a esto (o sin registrar): la pantalla
-- de caja la resalta igual que las citas sin cobrar.
-- =====================================================================

ALTER TABLE gift_card ADD COLUMN payment_method payment_method;
ALTER TABLE gift_card ADD COLUMN sold_location_id BIGINT REFERENCES location(id);

ALTER TABLE customer_pack ADD COLUMN payment_method payment_method;
ALTER TABLE customer_pack ADD COLUMN sold_location_id BIGINT REFERENCES location(id);

CREATE INDEX idx_gift_card_sold_location ON gift_card (sold_location_id, created_at);
CREATE INDEX idx_customer_pack_sold_location ON customer_pack (sold_location_id, sold_at);
