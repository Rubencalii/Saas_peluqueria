-- =====================================================================
-- 0009_password_reset.sql · Reset de contraseña del panel (doc 14 §9)
--
-- Token de un solo uso para que un usuario del panel restablezca su contraseña.
-- Se guarda el HASH del token (no el token en claro), con caducidad y marca de
-- uso, de modo que una fuga de BD no permita usar los tokens.
-- =====================================================================

CREATE TABLE password_reset (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    token_hash  TEXT NOT NULL,              -- sha256 del token enviado al usuario
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_password_reset_token ON password_reset (token_hash);
