-- =====================================================================
-- 0015_per_tenant_uniqueness.sql · Multi-tenant Fase 2 (doc 15)
--
-- Las unicidades que eran globales pasan a ser POR CUENTA: dos salones
-- distintos pueden tener el mismo slug de sede o el mismo teléfono de cliente.
-- El email de `app_user` SE MANTIENE global (decisión de producto: un email =
-- un usuario = una cuenta, login sin elegir salón). `staff.calendar_token` ya
-- es un secreto global y no cambia.
-- =====================================================================

ALTER TABLE location DROP CONSTRAINT IF EXISTS location_slug_key;
ALTER TABLE location ADD CONSTRAINT location_account_slug_key UNIQUE (account_id, slug);

ALTER TABLE customer DROP CONSTRAINT IF EXISTS customer_phone_key;
ALTER TABLE customer ADD CONSTRAINT customer_account_phone_key UNIQUE (account_id, phone);
