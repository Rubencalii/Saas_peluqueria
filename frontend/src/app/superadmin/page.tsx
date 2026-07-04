"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type SaAccount, type SaStats } from "@/lib/admin";

const STATUS: Record<string, { label: string; cls: string }> = {
  active: { label: "Activa", cls: "bg-emerald-100 text-emerald-800" },
  trial: { label: "Prueba", cls: "bg-sky-100 text-sky-800" },
  suspended: { label: "Suspendida", cls: "bg-red-100 text-red-700" },
  cancelled: { label: "Cancelada", cls: "bg-zinc-200 text-zinc-600" },
};
const PLANS = ["free", "pro", "cadena"];

export default function SuperAdminHome() {
  const [stats, setStats] = useState<SaStats | null>(null);
  const [accounts, setAccounts] = useState<SaAccount[]>([]);
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    const [s, a] = await Promise.all([admin.saStats(), admin.saAccounts()]);
    setStats(s);
    setAccounts(a.accounts);
    setLoading(false);
  }, []);

  useEffect(() => {
    load().catch(() => setLoading(false));
  }, [load]);

  async function setStatus(id: number, status: string) {
    await admin.saUpdateAccount(id, { status });
    await load();
  }
  async function setPlan(id: number, plan_code: string) {
    await admin.saUpdateAccount(id, { plan_code });
    await load();
  }

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="skeleton h-9 w-72" />
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="skeleton h-24" />
          ))}
        </div>
        <div className="skeleton h-64" />
      </div>
    );
  }

  const shown = accounts.filter((a) => {
    const q = query.trim().toLowerCase();
    return q === "" || a.name.toLowerCase().includes(q) || a.slug.toLowerCase().includes(q);
  });

  return (
    <div className="fade-up space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Cuentas de la plataforma</h1>
          <p className="mt-1 text-sm text-muted">
            Todos los salones del SaaS: su plan, su uso y su estado. Suspender deja la cuenta en solo lectura.
          </p>
        </div>
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Buscar cuenta o slug…"
          className="field mt-0 w-64"
        />
      </header>

      {stats ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          <Stat label="Cuentas" value={stats.accounts.total} />
          <Stat label="Activas" value={stats.accounts.active} dot="var(--accent)" />
          <Stat label="En prueba" value={stats.accounts.trial} dot="#38bdf8" />
          <Stat label="Suspendidas" value={stats.accounts.suspended} dot="#ef4444" />
          <Stat label="Citas (total)" value={stats.appointments_total} />
        </div>
      ) : null}

      <div className="card overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
              <th className="px-4 py-3">Cuenta</th>
              <th className="px-4 py-3">Estado</th>
              <th className="px-4 py-3">Plan</th>
              <th className="px-4 py-3">Uso</th>
              <th className="px-4 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            {shown.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-sm text-muted">
                  Ninguna cuenta encaja con «{query}».
                </td>
              </tr>
            ) : null}
            {shown.map((a) => {
              const st = STATUS[a.status] ?? { label: a.status, cls: "bg-zinc-200 text-zinc-600" };
              const suspended = a.status === "suspended" || a.status === "cancelled";
              return (
                <tr
                  key={a.id}
                  className={
                    "border-b border-border/60 transition last:border-0 hover:bg-brand-soft/40 " +
                    (suspended ? "opacity-60" : "")
                  }
                >
                  <td className="px-4 py-3">
                    <p className="font-medium">{a.name}</p>
                    <p className="text-xs text-muted">
                      {a.slug} · desde {new Date(a.created_at).toLocaleDateString("es-ES")}
                    </p>
                  </td>
                  <td className="px-4 py-3">
                    <span className={"chip " + st.cls}>{st.label}</span>
                  </td>
                  <td className="px-4 py-3">
                    <select
                      value={a.plan_code ?? ""}
                      onChange={(e) => setPlan(a.id, e.target.value)}
                      className="rounded-lg border border-border bg-card px-2 py-1 text-sm"
                    >
                      {a.plan_code === null ? <option value="">—</option> : null}
                      {PLANS.map((p) => (
                        <option key={p} value={p}>
                          {p}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td className="px-4 py-3 text-xs text-muted">
                    {a.counts.locations} sedes · {a.counts.users} usuarios · {a.counts.customers} clientes ·{" "}
                    {a.counts.appointments} citas
                  </td>
                  <td className="px-4 py-3 text-right">
                    {suspended ? (
                      <button onClick={() => setStatus(a.id, "active")} className="btn-ghost px-3 py-1.5 text-xs">
                        Reactivar
                      </button>
                    ) : (
                      <button
                        onClick={() => {
                          if (confirm(`¿Suspender "${a.name}"? Quedará en solo lectura.`)) void setStatus(a.id, "suspended");
                        }}
                        className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300"
                      >
                        Suspender
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Stat({ label, value, dot }: { label: string; value: number; dot?: string }) {
  return (
    <div className="card p-4 text-center">
      <p className="text-2xl font-bold tabular-nums">{value}</p>
      <p className="mt-0.5 flex items-center justify-center gap-1.5 text-xs text-muted">
        {dot ? <span className="h-2 w-2 rounded-full" style={{ background: dot }} /> : null}
        {label}
      </p>
    </div>
  );
}
