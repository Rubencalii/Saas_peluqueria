-- =====================================================================
-- 0016_account_wa_line.sql · Multi-tenant Fase 3 (doc 15)
--
-- Cada cuenta tiene su propia línea de WhatsApp (decisión de producto: una
-- línea de Meta por salón). El webhook resuelve el tenant por el
-- `phone_number_id` que Meta incluye en `value.metadata`. Nullable: en
-- desarrollo/mono-cadena no hay línea configurada y el bot cae en la principal.
-- =====================================================================

ALTER TABLE account ADD COLUMN wa_phone_number_id TEXT UNIQUE;
