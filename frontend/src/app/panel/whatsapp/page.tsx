"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type Conversation, type ConversationList } from "@/lib/admin";

export default function WhatsAppPage() {
  const [status, setStatus] = useState<"pendiente" | "all">("pendiente");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<ConversationList | null>(null);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<Conversation | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setList(await admin.conversations(status, page));
    } catch {
      setList(null);
    } finally {
      setLoading(false);
    }
  }, [status, page]);

  useEffect(() => {
    void load();
  }, [load]);

  function changeStatus(s: "pendiente" | "all") {
    setStatus(s);
    setPage(1);
  }

  const items = list?.conversations ?? [];
  const totalPages = list ? Math.max(1, Math.ceil(list.total / list.per_page)) : 1;

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">WhatsApp</h1>
        <div className="flex rounded-full border border-border bg-card p-0.5 text-sm">
          <Tab on={status === "pendiente"} onClick={() => changeStatus("pendiente")}>Pendientes</Tab>
          <Tab on={status === "all"} onClick={() => changeStatus("all")}>Todas</Tab>
        </div>
      </header>

      <div className="grid gap-5 md:grid-cols-[1fr_1.1fr]">
        <div>
          {loading ? (
            <div className="space-y-2">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-16 animate-pulse rounded-2xl bg-brand-soft/60" />
              ))}
            </div>
          ) : items.length === 0 ? (
            <p className="card p-6 text-center text-sm text-muted">
              {status === "pendiente" ? "No hay conversaciones esperando respuesta. 🎉" : "Sin conversaciones."}
            </p>
          ) : (
            <ul className="space-y-2">
              {items.map((c) => (
                <li key={c.wa_id}>
                  <button
                    onClick={() => setSelected(c)}
                    className={
                      "card w-full p-4 text-left transition hover:border-[var(--ring)] " +
                      (selected?.wa_id === c.wa_id ? "border-[var(--brand)]" : "")
                    }
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-medium">{c.customer_name ?? c.phone}</span>
                      {c.needs_human ? <span className="chip bg-amber-100 text-amber-800">pendiente</span> : null}
                    </div>
                    <p className="mt-0.5 text-sm text-muted">
                      {c.phone}
                      {c.location ? ` · ${c.location.name}` : ""} · {new Date(c.updated_at).toLocaleString("es-ES")}
                    </p>
                  </button>
                </li>
              ))}
            </ul>
          )}

          {list && totalPages > 1 ? (
            <div className="mt-3 flex items-center justify-between text-sm">
              <button
                disabled={page <= 1}
                onClick={() => setPage((p) => p - 1)}
                className="btn-ghost px-3 py-1.5 disabled:opacity-40"
              >
                Anterior
              </button>
              <span className="text-muted">{page} / {totalPages}</span>
              <button
                disabled={page >= totalPages}
                onClick={() => setPage((p) => p + 1)}
                className="btn-ghost px-3 py-1.5 disabled:opacity-40"
              >
                Siguiente
              </button>
            </div>
          ) : null}
        </div>

        <div className="md:sticky md:top-5 md:self-start">
          {selected ? (
            <Reply
              conversation={selected}
              onDone={async (resolved) => {
                // Al resolver, la conversación sale de "Pendientes": cierra la ficha.
                if (resolved && status === "pendiente") setSelected(null);
                await load();
              }}
            />
          ) : (
            <p className="card grid h-40 place-items-center p-6 text-center text-sm text-muted">
              Elige una conversación para responder.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

function Tab({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={"rounded-full px-3 py-1.5 font-medium transition " + (on ? "bg-brand text-brand-ink" : "text-muted")}
    >
      {children}
    </button>
  );
}

function Reply({ conversation, onDone }: { conversation: Conversation; onDone: (resolved: boolean) => void }) {
  const [message, setMessage] = useState("");
  const [resolve, setResolve] = useState(true);
  const [sending, setSending] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  async function send() {
    if (message.trim() === "") return;
    setSending(true);
    setMsg(null);
    try {
      await admin.replyConversation(conversation.wa_id, message.trim(), resolve);
      setMessage("");
      setMsg({ ok: true, text: resolve ? "Respondido y devuelto al bot." : "Respuesta enviada." });
      await onDone(resolve);
    } catch (e) {
      setMsg({ ok: false, text: e instanceof Error ? e.message : "No se pudo enviar." });
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="card space-y-3 p-5">
      <div>
        <h2 className="text-lg font-semibold">{conversation.customer_name ?? conversation.phone}</h2>
        <p className="text-sm text-muted">{conversation.phone}{conversation.location ? ` · ${conversation.location.name}` : ""}</p>
      </div>

      <textarea
        value={message}
        onChange={(e) => setMessage(e.target.value)}
        rows={4}
        placeholder="Escribe tu respuesta…"
        className="field resize-y"
      />

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={resolve} onChange={(e) => setResolve(e.target.checked)} className="h-4 w-4 accent-[var(--brand)]" />
        Devolver el control al bot (resolver)
      </label>

      {msg ? (
        <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
          {msg.text}
        </p>
      ) : null}

      <div className="flex justify-end">
        <button onClick={send} disabled={sending || message.trim() === ""} className="btn-primary px-5 py-2.5">
          {sending ? "Enviando…" : "Enviar por WhatsApp"}
        </button>
      </div>
    </div>
  );
}
