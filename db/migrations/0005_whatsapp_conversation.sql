-- =====================================================================
-- 0005_whatsapp_conversation.sql · Estado del bot de WhatsApp (doc 03, 06 §3)
--
-- El webhook recibe mensajes sueltos de Meta; el bot es una máquina de
-- estados guiada (menú → reservar/ver/cambiar). Persistimos el estado de
-- cada conversación por número (wa_id) para continuar el diálogo entre
-- peticiones, y deduplicamos los mensajes entrantes (Meta reintenta).
-- =====================================================================

-- Estado de la conversación, una fila por número de WhatsApp.
CREATE TABLE conversation (
    wa_id        TEXT PRIMARY KEY,                 -- teléfono del cliente (id de WhatsApp)
    state        TEXT NOT NULL DEFAULT 'menu',     -- paso actual del flujo
    data         JSONB NOT NULL DEFAULT '{}'::jsonb, -- datos acumulados del flujo
    location_id  BIGINT REFERENCES location(id),   -- sede en curso (multi-sede)
    needs_human  BOOLEAN NOT NULL DEFAULT FALSE,    -- derivada a atención humana (bandeja panel)
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_conversation_needs_human ON conversation (needs_human) WHERE needs_human;

-- Idempotencia de entrada: ids de mensajes ya procesados (Meta reintenta
-- la entrega hasta recibir 200; sin esto se duplicarían respuestas/citas).
CREATE TABLE wa_processed_message (
    message_id   TEXT PRIMARY KEY,
    received_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
