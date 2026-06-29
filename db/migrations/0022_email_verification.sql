-- =====================================================================
-- 0022_email_verification.sql · Verificación de email en el alta de salón
--
-- Al darse de alta, el administrador recibe un email con un enlace para
-- verificar su dirección (prueba de propiedad del email; reduce cuentas falsas).
-- token_hash = sha256 del token enviado; nunca se guarda el token en claro.
-- =====================================================================

ALTER TABLE app_user ADD COLUMN email_verified_at   TIMESTAMPTZ;
ALTER TABLE app_user ADD COLUMN email_verify_hash    TEXT;
ALTER TABLE app_user ADD COLUMN email_verify_expires TIMESTAMPTZ;

-- Los usuarios ya existentes (seed) se consideran verificados.
UPDATE app_user SET email_verified_at = now();
