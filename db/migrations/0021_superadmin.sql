-- =====================================================================
-- 0021_superadmin.sql · Super-admin de plataforma (operador del SaaS)
--
-- El super-admin está POR ENCIMA de los tenants: gestiona todas las cuentas
-- (suspender, reactivar, cambiar de plan, ver métricas). Su autorización va por
-- el flag `is_superadmin`, no por `account_id`. Vive en la cuenta principal por
-- conveniencia de FK, pero su ámbito es transversal.
-- =====================================================================

ALTER TABLE app_user ADD COLUMN is_superadmin BOOLEAN NOT NULL DEFAULT FALSE;

-- Usuario de plataforma (cámbiale la contraseña en producción).
INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active, is_superadmin)
VALUES (1, 'Plataforma', 'super@plataforma.es', crypt('super1234', gen_salt('bf')), 'admin_cadena', NULL, TRUE, TRUE);
