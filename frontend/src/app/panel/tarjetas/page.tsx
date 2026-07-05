"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type GiftCard, type GiftCardDetail } from "@/lib/admin";
import { formatPrice } from "@/lib/format";

export default function TarjetasPage() {
  const [recent, setRecent] = useState<GiftCard[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setRecent((await admin.giftCards()).gift_cards);
    } catch {
      setRecent([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Tarjetas regalo</h1>
        <p className="mt-1 text-sm text-muted">
          Saldo prepagado con código. Se venden aquí y se canjean en caja descontando el importe.
        </p>
      </header>

      <div className="grid gap-5 lg:grid-cols-2">
        <SellCard onSold={load} />
        <RedeemCard onRedeemed={load} />
      </div>

      <section className="card overflow-x-auto p-0">
        <p className="border-b border-border px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted">
          Últimas vendidas
        </p>
        {loading ? (
          <div className="space-y-2 p-4">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="skeleton h-10" />
            ))}
          </div>
        ) : recent.length === 0 ? (
          <p className="p-6 text-center text-sm text-muted">Aún no hay tarjetas vendidas. 🎁</p>
        ) : (
          <table className="w-full text-sm">
            <tbody>
              {recent.map((g) => (
                <tr key={g.code} className="border-b border-border/50 last:border-0">
                  <td className="px-4 py-2 font-mono text-xs">{g.code}</td>
                  <td className="px-4 py-2">{g.recipient_name ?? "—"}</td>
                  <td className="px-4 py-2 text-muted">
                    {new Date(g.created_at).toLocaleDateString("es-ES")}
                    {g.expires_at ? ` · caduca ${new Date(g.expires_at).toLocaleDateString("es-ES")}` : ""}
                  </td>
                  <td className="px-4 py-2 text-right font-semibold tabular-nums">
                    {formatPrice(g.balance)} <span className="text-xs font-normal text-muted">/ {formatPrice(g.initial_amount)}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  );
}

function SellCard({ onSold }: { onSold: () => void }) {
  const [amount, setAmount] = useState("");
  const [recipient, setRecipient] = useState("");
  const [validity, setValidity] = useState("");
  const [saving, setSaving] = useState(false);
  const [soldCode, setSoldCode] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function sell() {
    if (amount.trim() === "" || Number(amount) <= 0) {
      setError("Indica un importe válido.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const r = await admin.sellGiftCard({
        amount: Number(amount),
        recipient_name: recipient.trim() || null,
        validity_days: validity.trim() !== "" ? Number(validity) : null,
      });
      setSoldCode(r.code);
      setAmount("");
      setRecipient("");
      setValidity("");
      onSold();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo vender la tarjeta.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="card space-y-4 p-5">
      <h2 className="font-semibold">🎁 Vender tarjeta</h2>

      {soldCode ? (
        <div className="pop-in rounded-2xl bg-brand-soft p-4 text-center">
          <p className="text-sm text-muted">Tarjeta creada. Apunta el código:</p>
          <p className="mt-1 font-mono text-2xl font-bold tracking-wider">{soldCode}</p>
          <button onClick={() => setSoldCode(null)} className="btn-ghost mt-3 px-4 py-1.5 text-xs">
            Vender otra
          </button>
        </div>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="block text-sm font-semibold">
              Importe (€)
              <input type="number" min={1} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="50" className="field" />
            </label>
            <label className="block text-sm font-semibold">
              Para (opcional)
              <input value={recipient} onChange={(e) => setRecipient(e.target.value)} placeholder="Marta" className="field" />
            </label>
            <label className="block text-sm font-semibold">
              Validez (días)
              <input type="number" min={1} value={validity} onChange={(e) => setValidity(e.target.value)} placeholder="Sin caducidad" className="field" />
            </label>
          </div>

          {error ? <p className="text-sm text-red-700">{error}</p> : null}

          <button onClick={sell} disabled={saving} className="btn-primary w-full">
            {saving ? "Vendiendo…" : "Vender tarjeta"}
          </button>
        </>
      )}
    </section>
  );
}

function RedeemCard({ onRedeemed }: { onRedeemed: () => void }) {
  const [code, setCode] = useState("");
  const [card, setCard] = useState<GiftCardDetail | null>(null);
  const [amount, setAmount] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  async function lookup() {
    if (code.trim() === "") return;
    setBusy(true);
    setMsg(null);
    setCard(null);
    try {
      setCard((await admin.giftCard(code.trim())).gift_card);
    } catch {
      setMsg({ ok: false, text: "No se encontró ninguna tarjeta con ese código." });
    } finally {
      setBusy(false);
    }
  }

  async function redeem() {
    if (!card || amount.trim() === "") return;
    setBusy(true);
    setMsg(null);
    try {
      const r = await admin.redeemGiftCard(card.code, Number(amount));
      setMsg({ ok: true, text: `Canjeados ${formatPrice(Number(amount))}. Saldo restante: ${formatPrice(r.balance)}.` });
      setAmount("");
      setCard((await admin.giftCard(card.code)).gift_card);
      onRedeemed();
    } catch (e) {
      setMsg({ ok: false, text: e instanceof Error ? e.message : "No se pudo canjear." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card space-y-4 p-5">
      <h2 className="font-semibold">💳 Consultar y canjear</h2>

      <div className="flex gap-2">
        <input
          value={code}
          onChange={(e) => setCode(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") void lookup(); }}
          placeholder="GIFT-XXXX-XXXX"
          spellCheck={false}
          className="field mt-0 flex-1 font-mono"
        />
        <button onClick={lookup} disabled={busy || code.trim() === ""} className="btn-ghost">
          Buscar
        </button>
      </div>

      {card ? (
        <div className="space-y-3 rounded-2xl border border-border p-4">
          <div className="flex items-center justify-between">
            <span>
              <span className="font-mono text-sm font-semibold">{card.code}</span>
              {card.recipient_name ? <span className="block text-xs text-muted">Para {card.recipient_name}</span> : null}
              {card.expired ? <span className="chip mt-1 bg-red-100 text-red-700">caducada</span> : null}
            </span>
            <span className="text-2xl font-bold tabular-nums">{formatPrice(card.balance)}</span>
          </div>

          {!card.expired && card.balance > 0 ? (
            <div className="flex gap-2">
              <input
                type="number"
                min={0.01}
                step="0.01"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="Importe a canjear"
                className="field mt-0 flex-1"
              />
              <button onClick={redeem} disabled={busy || amount.trim() === ""} className="btn-primary px-5 py-2">
                Canjear
              </button>
            </div>
          ) : null}

          {card.redemptions.length > 0 ? (
            <div className="text-xs text-muted">
              {card.redemptions.map((r, i) => (
                <p key={i}>
                  −{formatPrice(r.amount)} · {new Date(r.redeemed_at).toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" })}
                  {r.redeemed_by ? ` · ${r.redeemed_by}` : ""}
                </p>
              ))}
            </div>
          ) : null}
        </div>
      ) : null}

      {msg ? (
        <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
          {msg.text}
        </p>
      ) : null}
    </section>
  );
}
