-- =====================================================================
-- 0019_account_locale.sql · Idioma de los mensajes por cuenta (i18n)
--
-- Cada salón elige el idioma de los mensajes al cliente (notificaciones).
-- Por defecto español. El dispatcher lo pasa a NotificationService.render().
-- =====================================================================

ALTER TABLE account ADD COLUMN locale TEXT NOT NULL DEFAULT 'es';
