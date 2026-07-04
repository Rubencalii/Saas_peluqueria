"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { admin, type WaitlistItem, type WaitlistList } from "@/lib/admin";

const STATUS: Record<string, string> = {
  esperando: "Esperando",
  avisado: "Avisado",
  convertido: "Convertido",
  cancelado: "Cancelado",
};

export default function EsperaPage() {
  const [status, setStatus] = useState("esperando");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<WaitlistList | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setList(await admin.waitlist(null, status, page));
    } catch {
      setList(null);
    } finally {
      setLoading(false);
    }
  }, [status, page]);

  useEffect(() => {
    void load();
  }, [load]);

  const items = list?.waitlist ?? [];
  const totalPages = list ? Math.max(1, Math.ceil(list.total / list.per_page)) : 1;

  async function cancel(id: number) {
    if (!confirm("¿Dar de baja esta entrada de la lista de espera?")) return;
    await admin.cancelWaitlist(id);
    await load();
  }

  async function convert(id: number) {
    await admin.convertWaitlist(id);
    await load();
  }

  /** Abre la agenda con Nueva cita prerrellenada con los datos de la espera. */
  function bookUrl(w: WaitlistItem): string {
    const q = new URLSearchParams({
      nueva: "1",
      location_id: String(w.location.id),
      service_id: String(w.service.id),
      name: w.customer.name,
      phone: w.customer.phone,
    });
    if (w.desired_date) q.set("date", w.desired_date);
    return `/panel/agenda?${q.toString()}`;
  }

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Lista de espera</h1>
        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="rounded-xl border border-border bg-card px-3 py-2 text-sm">
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
                <div className="flex flex-wrap gap-2">
                  <Link href={bookUrl(w)} className="btn-ghost px-3 py-1.5 text-xs">
                    📅 Crear cita
                  </Link>
                  <button
                    onClick={() => convert(w.id)}
                    title="El cliente ya tiene cita: cierra la espera"
                    className="btn-ghost px-3 py-1.5 text-xs"
                  >
                    ✓ Convertida
                  </button>
                  <button onClick={() => cancel(w.id)} className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300">
                    Dar de baja
                  </button>
                </div>
              ) : (
                <span className="chip bg-brand-soft capitalize">{STATUS[w.status] ?? w.status}</span>
              )}
            </li>
          ))}
        </ul>
      )}

      {!loading && list && totalPages > 1 ? (
        <div className="flex items-center justify-between text-sm">
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
  );
}
