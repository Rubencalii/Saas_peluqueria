-- =====================================================================
-- 0025_tarjetas_regalo.sql · Tarjetas regalo (doc 13 §2)
--
-- Saldo prepagado al portador: el salón la vende con un código legible
-- (GIFT-XXXX-XXXX), quien la recibe la gasta en caja y recepción la canjea
-- por código descontando el importe. Cada canje queda en el libro
-- gift_card_redemption (auditoría del saldo).
-- =====================================================================

CREATE TABLE gift_card (
    id             BIGSERIAL PRIMARY KEY,
    account_id     BIGINT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
    code           TEXT NOT NULL UNIQUE,               -- GIFT-XXXX-XXXX (sin caracteres ambiguos)
    initial_amount NUMERIC(10,2) NOT NULL CHECK (initial_amount > 0),
    balance        NUMERIC(10,2) NOT NULL CHECK (balance >= 0),
    recipient_name TEXT,                                -- para quién es (opcional)
    expires_at     TIMESTAMPTZ,                         -- NULL = sin caducidad
    sold_by        BIGINT REFERENCES app_user(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_gift_card_account ON gift_card (account_id, created_at DESC);

CREATE TABLE gift_card_redemption (
    id           BIGSERIAL PRIMARY KEY,
    gift_card_id BIGINT NOT NULL REFERENCES gift_card(id) ON DELETE CASCADE,
    amount       NUMERIC(10,2) NOT NULL CHECK (amount > 0),
    redeemed_by  BIGINT REFERENCES app_user(id),
    redeemed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_gift_card_redemption_card ON gift_card_redemption (gift_card_id);

-- Multi-tenant: tabla raíz con account_id → misma política que 0017/0024.
ALTER TABLE gift_card ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON gift_card;
CREATE POLICY tenant_isolation ON gift_card
    USING (account_id = current_setting('app.account_id', true)::bigint)
    WITH CHECK (true);
