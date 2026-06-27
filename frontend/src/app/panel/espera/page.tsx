"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type WaitlistItem } from "@/lib/admin";

const STATUS: Record<string, string> = {
  esperando: "Esperando",
  avisado: "Avisado",
  convertido: "Convertido",
  cancelado: "Cancelado",
};

export default function EsperaPage() {
  const [status, setStatus] = useState("esperando");
  const [items, setItems] = useState<WaitlistItem[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await admin.waitlist(null, status, 1);
      setItems(r.waitlist);
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [status]);

  useEffect(() => {
    void load();
  }, [load]);

  async function cancel(id: number) {
    if (!confirm("¿Dar de baja esta entrada de la lista de espera?")) return;
    await admin.cancelWaitlist(id);
    await load();
  }

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Lista de espera</h1>
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-xl border border-border bg-card px-3 py-2 text-sm">
          {Object.entries(STATUS).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
      </header>

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-2xl bg-brand-soft/60" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">No hay nadie en este estado. 🙌</p>
      ) : (
        <ul className="space-y-2">
          {items.map((w) => (
            <li key={w.id} className="card flex flex-wrap items-center justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="font-medium">
                  {w.customer.name} <span className="text-muted">· {w.customer.phone}</span>
                </p>
                <p className="mt-0.5 text-sm text-muted">
                  {w.service.name} · {w.location.name}
                  {w.staff ? ` · ${w.staff.name}` : ""}
                  {w.desired_date ? ` · prefiere ${w.desired_date}` : ""}
                </p>
              </div>
              {w.status === "esperando" || w.status === "avisado" ? (
                <button onClick={() => cancel(w.id)} className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300">
                  Dar de baja
                </button>
              ) : (
                <span className="chip bg-brand-soft capitalize">{STATUS[w.status] ?? w.status}</span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
