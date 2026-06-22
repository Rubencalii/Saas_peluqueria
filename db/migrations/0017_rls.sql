-- =====================================================================
-- 0017_rls.sql · Multi-tenant Fase 4 (doc 15): Row-Level Security
--
-- Red de seguridad a nivel de BD: aunque una consulta del código olvidara el
-- `WHERE account_id`, la BD no dejaría ver/escribir filas de otra cuenta.
--
-- Diseño SIN romper lo actual:
--  - Se crea un rol de aplicación `peluqueria_app` (LOGIN, sin BYPASSRLS).
--  - RLS se ENABLE (no FORCE) en las tablas raíz con `account_id`. El OWNER
--    (migraciones, cron, tests) IGNORA las políticas; el rol de app SÍ queda
--    sujeto. Activarlo es *opt-in*: basta con que la web se conecte como
--    `peluqueria_app` (DATABASE_URL) y un listener fije `app.account_id`.
--  - Sin `app.account_id` el rol de app no ve nada (fail-closed), así que cada
--    petición debe fijarlo (lo hace TenantSessionListener).
--
-- Tablas con `account_id` directo: location/service/staff/customer. Sus hijas
-- (appointment, waitlist, …) quedan acotadas por el código + sus FKs a estas.
-- =====================================================================

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'peluqueria_app') THEN
        CREATE ROLE peluqueria_app LOGIN PASSWORD 'peluqueria_app';
    END IF;
END
$$;

GRANT USAGE ON SCHEMA public TO peluqueria_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO peluqueria_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO peluqueria_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO peluqueria_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO peluqueria_app;

DO $$
DECLARE
    t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['location', 'service', 'staff', 'customer']
    LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I', t);
        -- USING acota lectura/edición/borrado a la cuenta de la sesión; WITH CHECK
        -- permite que el código fije account_id en los INSERT (ya validado en la app).
        EXECUTE format(
            'CREATE POLICY tenant_isolation ON %I '
            || 'USING (account_id = current_setting(''app.account_id'', true)::bigint) '
            || 'WITH CHECK (true)',
            t
        );
    END LOOP;
END
$$;
