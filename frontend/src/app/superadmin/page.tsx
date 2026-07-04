"use client";

import { Fragment, useCallback, useEffect, useState } from "react";
import { admin, getToken, setToken, type SaAccount, type SaAccountDetail, type SaStats } from "@/lib/admin";

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
  const [openId, setOpenId] = useState<number | null>(null);
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
  async function setPlan(a: SaAccount, plan_code: string) {
    if (
      a.stripe_managed &&
      !confirm(
        `La suscripción de "${a.name}" la gestiona Stripe: cambiar el plan aquí NO cambia el cobro real. ` +
          "Hazlo solo como corrección puntual. ¿Continuar?",
      )
    ) {
      return;
    }
    await admin.saUpdateAccount(a.id, { plan_code });
    await load();
  }

  // Impersonación para soporte: guarda la sesión de superadmin para poder
  // volver, cambia el token por el de la cuenta y entra en su panel.
  async function impersonate(id: number) {
    const r = await admin.saImpersonate(id);
    const current = getToken();
    if (current) localStorage.setItem("sa_return_token", current);
    localStorage.setItem("sa_impersonating", r.account.name);
    setToken(r.token);
    window.location.href = "/panel";
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

      {stats && stats.signups_8w.length > 0 ? <SignupsChart data={stats.signups_8w} /> : null}

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
                <Fragment key={a.id}>
                <tr
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
                    <span className="flex items-center gap-1.5">
                      <select
                        value={a.plan_code ?? ""}
                        onChange={(e) => setPlan(a, e.target.value)}
                        className="rounded-lg border border-border bg-card px-2 py-1 text-sm"
                      >
                        {a.plan_code === null ? <option value="">—</option> : null}
                        {PLANS.map((p) => (
                          <option key={p} value={p}>
                            {p}
                          </option>
                        ))}
                      </select>
                      {a.stripe_managed ? (
                        <span title="Suscripción gestionada por Stripe" className="text-xs">
                          💳
                        </span>
                      ) : null}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-xs text-muted">
                    {a.counts.locations} sedes · {a.counts.users} usuarios · {a.counts.customers} clientes ·{" "}
                    {a.counts.appointments} citas
                  </td>
                  <td className="px-4 py-3 text-right">
                    <span className="inline-flex gap-1.5">
                      <button
                        onClick={() => setOpenId(openId === a.id ? null : a.id)}
                        className="btn-ghost px-3 py-1.5 text-xs"
                      >
                        {openId === a.id ? "Cerrar" : "Ficha"}
                      </button>
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
                    </span>
                  </td>
                </tr>
                {openId === a.id ? (
                  <tr className="border-b border-border/60 last:border-0">
                    <td colSpan={5} className="bg-brand-soft/30 px-4 py-4">
                      <AccountDetail id={a.id} onImpersonate={() => impersonate(a.id)} />
                    </td>
                  </tr>
                ) : null}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** Barras simples de altas por semana (últimas 8). */
function SignupsChart({ data }: { data: Array<{ week: string; count: number }> }) {
  const max = Math.max(1, ...data.map((d) => d.count));
  return (
    <div className="card p-4">
      <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">
        Altas de cuentas · últimas 8 semanas
      </p>
      <div className="flex h-24 items-end gap-2">
        {data.map((d) => (
          <div key={d.week} className="flex flex-1 flex-col items-center gap-1" title={`Semana del ${d.week}: ${d.count}`}>
            <span className="text-xs font-semibold tabular-nums">{d.count}</span>
            <div
              className="w-full rounded-t-md"
              style={{
                height: `${Math.max(6, (d.count / max) * 64)}px`,
                background: d.count > 0 ? "var(--brand)" : "var(--border)",
              }}
            />
            <span className="text-[10px] text-muted">
              {new Date(d.week).toLocaleDateString("es-ES", { day: "2-digit", month: "2-digit" })}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/** Ficha expandida de una cuenta: contacto, sedes, suscripción y actividad. */
function AccountDetail({ id, onImpersonate }: { id: number; onImpersonate: () => void }) {
  const [detail, setDetail] = useState<SaAccountDetail | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    admin.saAccount(id).then(setDetail).catch(() => setFailed(true));
  }, [id]);

  if (failed) return <p className="text-sm text-muted">No se pudo cargar la ficha.</p>;
  if (!detail) return <div className="skeleton h-24" />;

  return (
    <div className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Contacto</p>
        {detail.admins.length === 0 ? (
          <p className="text-muted">Sin administradores.</p>
        ) : (
          detail.admins.map((u) => (
            <p key={u.id} className={u.active ? "" : "line-through opacity-60"}>
              {u.name} ·{" "}
              <a href={`mailto:${u.email}`} className="underline-offset-2 hover:underline">
                {u.email}
              </a>
            </p>
          ))
        )}
      </div>

      <div>
        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Sedes</p>
        {detail.locations.length === 0 ? (
          <p className="text-muted">Sin sedes.</p>
        ) : (
          detail.locations.map((l) => (
            <p key={l.id} className={l.active ? "" : "opacity-60"}>
              {l.name} <span className="text-muted">/{l.slug}</span>
              {!l.active ? " · inactiva" : ""}
            </p>
          ))
        )}
      </div>

      <div>
        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Suscripción</p>
        {detail.subscription ? (
          <>
            <p>
              {detail.subscription.plan_name ?? detail.subscription.plan_code} ·{" "}
              <span className="text-muted">{detail.subscription.status}</span>
            </p>
            <p className="text-muted">
              {detail.subscription.stripe_managed ? "💳 Gestionada por Stripe" : "Sin cobro automático"}
              {detail.subscription.current_period_end
                ? ` · renueva ${new Date(detail.subscription.current_period_end).toLocaleDateString("es-ES")}`
                : ""}
            </p>
          </>
        ) : (
          <p className="text-muted">Sin suscripción.</p>
        )}
        <p className="mt-1 text-muted">
          Actividad: {detail.activity.appointments_30d} citas en 30 días
          {detail.activity.last_appointment_at
            ? ` · última ${new Date(detail.activity.last_appointment_at).toLocaleDateString("es-ES")}`
            : " · sin citas aún"}
        </p>
      </div>

      <div className="flex items-start justify-end">
        <button
          onClick={() => {
            if (
              confirm(
                `Vas a entrar en el panel de "${detail.account.name}" con la sesión de su administrador. ` +
                  "La acción queda registrada. ¿Continuar?",
              )
            ) {
              onImpersonate();
            }
          }}
          className="btn-primary px-4 py-2 text-xs"
        >
          🎧 Entrar como esta cuenta
        </button>
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
