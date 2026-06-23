"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type CustomerDetail, type CustomerList } from "@/lib/admin";
import { formatDateLong, formatTime } from "@/lib/format";

export default function ClientesPage() {
  const [query, setQuery] = useState("");
  const [debounced, setDebounced] = useState("");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<CustomerList | null>(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<number | null>(null);

  useEffect(() => {
    const t = setTimeout(() => {
      setDebounced(query);
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [query]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setList(await admin.customers(debounced, page));
    } catch {
      setList(null);
    } finally {
      setLoading(false);
    }
  }, [debounced, page]);

  useEffect(() => {
    void load();
  }, [load]);

  const totalPages = list ? Math.max(1, Math.ceil(list.total / list.per_page)) : 1;

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Clientes</h1>
        {list ? <span className="text-sm text-muted">{list.total} en total</span> : null}
      </header>

      <input
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder="Buscar por nombre o teléfono…"
        className="field mt-0"
      />

      <div className="grid gap-5 md:grid-cols-[1fr_1.1fr]">
        <div>
          {loading ? (
            <div className="space-y-2">
              {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="h-14 animate-pulse rounded-2xl bg-brand-soft/60" />
              ))}
            </div>
          ) : list && list.customers.length > 0 ? (
            <ul className="space-y-2">
              {list.customers.map((c) => (
                <li key={c.id}>
                  <button
                    onClick={() => setSelected(c.id)}
                    className={
                      "card w-full p-4 text-left transition hover:border-[var(--ring)] " +
                      (selected === c.id ? "border-[var(--brand)]" : "")
                    }
                  >
                    <p className="font-medium">{c.name}</p>
                    <p className="text-sm text-muted">
                      {c.phone}
                      {c.email ? ` · ${c.email}` : ""}
                    </p>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="card p-6 text-center text-sm text-muted">Sin resultados.</p>
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
              <span className="text-muted">
                {page} / {totalPages}
              </span>
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
          {selected ? <CustomerCard id={selected} onClose={() => setSelected(null)} /> : (
            <p className="card grid h-40 place-items-center p-6 text-center text-sm text-muted">
              Selecciona un cliente para ver su ficha.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

function CustomerCard({ id, onClose }: { id: number; onClose: () => void }) {
  const [data, setData] = useState<CustomerDetail | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    admin
      .customer(id)
      .then((r) => setData(r.customer))
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) return <div className="card h-40 animate-pulse" />;
  if (!data) return <p className="card p-6 text-sm text-muted">No se pudo cargar la ficha.</p>;

  return (
    <div className="card p-5">
      <div className="flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold">{data.name}</h2>
          <p className="text-sm text-muted">{data.phone}</p>
          {data.email ? <p className="text-sm text-muted">{data.email}</p> : null}
        </div>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <div className="mt-4 flex flex-wrap gap-2 text-xs">
        <span className="chip bg-brand-soft">⭐ {data.loyalty?.points ?? 0} puntos</span>
        <span className={"chip " + (data.wa_consent ? "bg-emerald-100 text-emerald-800" : "bg-zinc-200 text-zinc-600")}>
          WhatsApp {data.wa_consent ? "sí" : "no"}
        </span>
      </div>

      <h3 className="mt-5 mb-2 text-sm font-semibold">Últimas citas</h3>
      {data.appointments.length === 0 ? (
        <p className="text-sm text-muted">Aún no tiene citas.</p>
      ) : (
        <ul className="space-y-2">
          {data.appointments.slice(0, 6).map((a) => (
            <li key={a.appointment_id} className="rounded-xl bg-brand-soft/50 px-3 py-2 text-sm">
              <span className="font-medium capitalize">{formatDateLong(a.start, "Europe/Madrid")}</span> ·{" "}
              {formatTime(a.start, "Europe/Madrid")} h
              <span className="block text-muted">
                {a.service_name} · {a.location_name}
                {a.staff_name ? ` · ${a.staff_name}` : ""} · {a.status}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
