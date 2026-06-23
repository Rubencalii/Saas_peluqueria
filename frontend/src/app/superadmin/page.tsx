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

  if (loading) return <div className="card h-64 animate-pulse" />;

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold tracking-tight">Cuentas de la plataforma</h1>

      {stats ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          <Stat label="Cuentas" value={stats.accounts.total} />
          <Stat label="Activas" value={stats.accounts.active} />
          <Stat label="En prueba" value={stats.accounts.trial} />
          <Stat label="Suspendidas" value={stats.accounts.suspended} />
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
            {accounts.map((a) => {
              const st = STATUS[a.status] ?? { label: a.status, cls: "bg-zinc-200 text-zinc-600" };
              const suspended = a.status === "suspended" || a.status === "cancelled";
              return (
                <tr key={a.id} className="border-b border-border/60 last:border-0">
                  <td className="px-4 py-3">
                    <p className="font-medium">{a.name}</p>
                    <p className="text-xs text-muted">{a.slug}</p>
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

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="card p-4 text-center">
      <p className="text-2xl font-bold">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}
