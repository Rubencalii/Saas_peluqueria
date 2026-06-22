-- =====================================================================
-- 0018_session_revocation.sql · Revocación de sesiones del panel
--
-- El JWT es sin estado (HS256). Para poder INVALIDAR sesiones (logout en todos
-- los dispositivos, o tras cambiar la contraseña) se guarda una "versión de
-- token" por usuario: el JWT lleva la versión vigente al emitirse (claim `tv`),
-- y al revocar se incrementa, de modo que los tokens anteriores dejan de valer.
-- Es preciso (entero), sin depender de la resolución temporal del `iat`.
-- =====================================================================

ALTER TABLE app_user ADD COLUMN token_version INTEGER NOT NULL DEFAULT 0;
