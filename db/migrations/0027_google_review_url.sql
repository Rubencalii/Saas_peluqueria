-- =====================================================================
-- 0027_google_review_url.sql · Enlace de reseñas de Google por sede
--
-- Cierra el circuito de valoraciones: al completar una cita se envía el
-- enlace a /valorar; si la nota es alta (>=4) y la sede tiene este enlace,
-- se invita al cliente a dejar también la reseña en Google.
-- =====================================================================

ALTER TABLE location ADD COLUMN google_review_url TEXT;
