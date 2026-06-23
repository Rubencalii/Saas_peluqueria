"use client";

import { useEffect, useState } from "react";
import { admin, AdminApiError, type Account } from "@/lib/admin";

const PLAN_LABEL: Record<string, string> = { free: "Gratis", pro: "Pro", cadena: "Cadena" };
const ACCOUNT_STATUS: Record<string, { label: string; cls: string }> = {
  active: { label: "Activa", cls: "bg-emerald-100 text-emerald-800" },
  trial: { label: "Prueba", cls: "bg-sky-100 text-sky-800" },
  suspended: { label: "Suspendida", cls: "bg-red-100 text-red-700" },
  cancelled: { label: "Cancelada", cls: "bg-zinc-200 text-zinc-600" },
};

export default function CuentaPage() {
  const [data, setData] = useState<Account | null>(null);
  const [loading, setLoading] = useState(true);
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    admin
      .account()
      .then(setData)
      .catch(() => setMsg("No se pudo cargar la cuenta."))
      .finally(() => setLoading(false));
  }, []);

  async function go(action: () => Promise<{ url: string }>) {
    setBusy(true);
    setMsg(null);
    try {
      const { url } = await action();
      window.location.href = url;
    } catch (e) {
      if (e instanceof AdminApiError && e.status === 503) {
        setMsg("La facturación no está activada en este entorno (faltan las claves de Stripe).");
      } else if (e instanceof AdminApiError && e.code === "PLAN_NOT_BILLABLE") {
        setMsg("Ese plan aún no tiene precio configurado en Stripe.");
      } else {
        setMsg(e instanceof Error ? e.message : "No se pudo abrir la facturación.");
      }
      setBusy(false);
    }
  }

  if (loading) return <div className="card h-48 animate-pulse" />;
  if (!data) return <p className="text-sm text-red-700">{msg ?? "Error."}</p>;

  const st = ACCOUNT_STATUS[data.account.status] ?? { label: data.account.status, cls: "bg-zinc-200 text-zinc-600" };
  const sub = data.subscription;

  return (
    <div className="space-y-5">
      <h1 className="text-2xl font-bold tracking-tight">Cuenta</h1>

      <section className="card p-5">
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-lg font-semibold">{data.account.name}</h2>
            <p className="text-sm text-muted">{data.account.slug}.reservas.app</p>
          </div>
          <span className={"chip " + st.cls}>{st.label}</span>
        </div>
      </section>

      <section className="card p-5">
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Suscripción</h2>
        {sub ? (
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-2xl font-bold">{PLAN_LABEL[sub.plan_code] ?? sub.plan_name}</span>
              <span className="chip bg-brand-soft capitalize">{sub.status}</span>
            </div>
            <ul className="grid grid-cols-3 gap-2 text-center text-sm">
              <Limit label="Sedes" value={sub.limits.max_locations} />
              <Limit label="Personal" value={sub.limits.max_staff} />
              <Limit label="Citas/mes" value={sub.limits.max_appointments_month} />
            </ul>
            {sub.current_period_end ? (
              <p className="text-xs text-muted">
                Periodo actual hasta {new Date(sub.current_period_end).toLocaleDateString("es-ES")}.
              </p>
            ) : null}
          </div>
        ) : (
          <p className="text-sm text-muted">Sin suscripción activa.</p>
        )}

        {msg ? <p className="mt-4 rounded-xl bg-brand-soft/60 px-3 py-2 text-sm">{msg}</p> : null}

        <div className="mt-4 flex flex-wrap gap-2">
          <button disabled={busy} onClick={() => go(() => admin.billingPortal())} className="btn-ghost">
            Gestionar facturación
          </button>
          {["pro", "cadena"].map((plan) => (
            <button
              key={plan}
              disabled={busy}
              onClick={() => go(() => admin.billingCheckout(plan))}
              className="btn-primary px-4 py-2.5"
            >
              Cambiar a {PLAN_LABEL[plan]}
            </button>
          ))}
        </div>
      </section>
    </div>
  );
}

function Limit({ label, value }: { label: string; value: number | null }) {
  return (
    <li className="rounded-xl bg-brand-soft/50 py-3">
      <span className="block text-lg font-bold">{value === null ? "∞" : value}</span>
      <span className="text-xs text-muted">{label}</span>
    </li>
  );
}
