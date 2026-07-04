-- =====================================================================
-- 0023_totp.sql · Doble factor (TOTP) para usuarios del panel
--
-- NULL = 2FA desactivado. Con secreto, el login exige además el código
-- de la app de autenticación (Google Authenticator, Aegis, 1Password…).
-- Especialmente recomendado para el superadmin de plataforma.
-- =====================================================================

ALTER TABLE app_user ADD COLUMN totp_secret TEXT;
