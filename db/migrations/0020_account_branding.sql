-- =====================================================================
-- 0020_account_branding.sql · Marca (white-label) por cuenta (doc 08)
--
-- Cada empresa personaliza el aspecto de su web de reserva y su panel desde la
-- configuración: nombre visible, color de marca, color de acento y logo. Todo
-- nullable → si está vacío, se usa el tema por defecto. El resto de tonos
-- (suave, hover, texto sobre marca) los deriva el frontend del color de marca.
-- =====================================================================

ALTER TABLE account ADD COLUMN display_name TEXT;
ALTER TABLE account ADD COLUMN brand_color  TEXT;   -- hex #rrggbb
ALTER TABLE account ADD COLUMN accent_color TEXT;   -- hex #rrggbb
ALTER TABLE account ADD COLUMN logo_url     TEXT;
