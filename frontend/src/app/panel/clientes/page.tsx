"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type CustomerDetail, type CustomerList } from "@/lib/admin";
import { downloadCsv, toCsv } from "@/lib/csv";
import { formatDateLong, formatTime } from "@/lib/format";
import { nextAppointment } from "@/lib/dashboard";

type ConsentFilter = "" | "yes" | "no";

const CONSENT_TABS: { value: ConsentFilter; label: string }[] = [
  { value: "", label: "Todos" },
  { value: "yes", label: "Con WhatsApp" },
  { value: "no", label: "Sin WhatsApp" },
];

export default function ClientesPage() {
  const [query, setQuery] = useState("");
  const [debounced, setDebounced] = useState("");
  const [consent, setConsent] = useState<ConsentFilter>("");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<CustomerList | null>(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<number | null>(null);
  const [canGdpr, setCanGdpr] = useState(false);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    admin
      .me()
      .then((r) => setCanGdpr(r.user.role === "admin_sede" || r.user.role === "admin_cadena"))
      .catch(() => {});
  }, []);

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
      setList(await admin.customers(debounced, page, consent));
    } catch {
      setList(null);
    } finally {
      setLoading(false);
    }
  }, [debounced, page, consent]);

  useEffect(() => {
    void load();
  }, [load]);

  const totalPages = list ? Math.max(1, Math.ceil(list.total / list.per_page)) : 1;

  // Exporta el listado COMPLETO con el filtro actual (búsqueda + consentimiento).
  async function exportCsv() {
    setExporting(true);
    try {
      const rows: Array<Array<string | number | null>> = [];
      for (let p = 1; p <= 500; p++) {
        const chunk = await admin.customers(debounced, p, consent);
        for (const c of chunk.customers) {
          rows.push([
            c.name,
            c.phone,
            c.email,
            c.wa_consent ? "sí" : "no",
            new Date(c.created_at).toLocaleDateString("es-ES"),
          ]);
        }
        if (p * chunk.per_page >= chunk.total) break;
      }
      const suffix = consent === "yes" ? "_con-whatsapp" : consent === "no" ? "_sin-whatsapp" : "";
      downloadCsv(
        `clientes${suffix}.csv`,
        toCsv(["Nombre", "Teléfono", "Email", "Consentimiento WhatsApp", "Alta"], rows),
      );
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Clientes</h1>
        <div className="flex items-center gap-3">
          {list ? <span className="text-sm text-muted">{list.total} en total</span> : null}
          <button
            onClick={exportCsv}
            disabled={exporting || !list || list.total === 0}
            className="btn-ghost px-3 py-1.5 text-xs"
          >
            {exporting ? "Exportando…" : "⬇ Exportar CSV"}
          </button>
        </div>
      </header>

      <input
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder="Buscar por nombre o teléfono…"
        className="field mt-0"
      />

      <div className="flex gap-2">
        {CONSENT_TABS.map((t) => (
          <button
            key={t.value}
            onClick={() => { setConsent(t.value); setPage(1); }}
            className={
              "chip transition " +
              (consent === t.value ? "bg-brand-soft text-foreground" : "bg-transparent text-muted hover:bg-brand-soft/60")
            }
          >
            {t.label}
          </button>
        ))}
      </div>

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
          {selected ? (
            <CustomerCard
              id={selected}
              canGdpr={canGdpr}
              onClose={() => setSelected(null)}
              onChanged={async () => { await load(); }}
              onAnonymized={async () => { setSelected(null); await load(); }}
            />
          ) : (
            <p className="card grid h-40 place-items-center p-6 text-center text-sm text-muted">
              Selecciona un cliente para ver su ficha.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

function CustomerCard({
  id,
  canGdpr,
  onClose,
  onChanged,
  onAnonymized,
}: {
  id: number;
  canGdpr: boolean;
  onClose: () => void;
  onChanged: () => void;
  onAnonymized: () => void;
}) {
  const [data, setData] = useState<CustomerDetail | null>(null);
  const [next, setNext] = useState<CustomerDetail["appointments"][number] | null>(null);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const reload = useCallback(() => {
    setLoading(true);
    admin
      .customer(id)
      .then((r) => {
        setData(r.customer);
        setName(r.customer.name);
        setEmail(r.customer.email ?? "");
        setNext(nextAppointment(r.customer.appointments, Date.now()));
      })
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => {
    setEditing(false);
    setMsg(null);
    reload();
  }, [reload]);

  async function saveEdit() {
    if (name.trim() === "") return;
    setBusy(true);
    setMsg(null);
    try {
      await admin.updateCustomer(id, { name: name.trim(), email: email.trim() || null });
      setEditing(false);
      reload();
      onChanged();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "No se pudo guardar.");
    } finally {
      setBusy(false);
    }
  }

  async function exportData() {
    setBusy(true);
    setMsg(null);
    try {
      const blob = await admin.exportCustomer(id);
      const url = URL.createObjectURL(new Blob([JSON.stringify(blob, null, 2)], { type: "application/json" }));
      const a = document.createElement("a");
      a.href = url;
      a.download = `cliente-${id}.json`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "No se pudo exportar.");
    } finally {
      setBusy(false);
    }
  }

  async function anonymize() {
    if (!confirm("Anonimizar borra los datos personales del cliente (se conservan las citas por ley). No se puede deshacer. ¿Continuar?")) return;
    setBusy(true);
    setMsg(null);
    try {
      await admin.anonymizeCustomer(id);
      onAnonymized();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "No se pudo anonimizar.");
      setBusy(false);
    }
  }

  if (loading) return <div className="card h-40 animate-pulse" />;
  if (!data) return <p className="card p-6 text-sm text-muted">No se pudo cargar la ficha.</p>;

  return (
    <div className="card p-5">
      <div className="flex items-start justify-between">
        {editing ? (
          <div className="flex-1 space-y-2 pr-3">
            <input value={name} onChange={(e) => setName(e.target.value)} className="field mt-0" placeholder="Nombre" />
            <input value={email} onChange={(e) => setEmail(e.target.value)} className="field mt-0" placeholder="Email (opcional)" />
          </div>
        ) : (
          <div>
            <h2 className="text-lg font-semibold">{data.name}</h2>
            <p className="text-sm text-muted">{data.phone}</p>
            {data.email ? <p className="text-sm text-muted">{data.email}</p> : null}
          </div>
        )}
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <div className="mt-4 flex flex-wrap gap-2 text-xs">
        <span className="chip bg-brand-soft">⭐ {data.loyalty?.points ?? 0} puntos</span>
        <span className={"chip " + (data.wa_consent ? "bg-emerald-100 text-emerald-800" : "bg-zinc-200 text-zinc-600")}>
          WhatsApp {data.wa_consent ? "sí" : "no"}
        </span>
      </div>

      {next ? (
        <div className="mt-4 rounded-xl border border-[var(--brand)] bg-brand-soft/50 px-3 py-2 text-sm">
          <span className="font-medium">Próxima cita:</span>{" "}
          <span className="capitalize">{formatDateLong(next.start, "Europe/Madrid")}</span> ·{" "}
          {formatTime(next.start, "Europe/Madrid")} h
          <span className="block text-muted">
            {next.service_name} · {next.location_name}
            {next.staff_name ? ` · ${next.staff_name}` : ""}
          </span>
        </div>
      ) : null}

      {msg ? <p className="mt-3 text-sm text-red-700">{msg}</p> : null}

      <div className="mt-3 flex flex-wrap gap-2">
        {editing ? (
          <>
            <button onClick={saveEdit} disabled={busy} className="btn-primary px-3 py-1.5 text-xs">Guardar</button>
            <button onClick={() => { setEditing(false); setName(data.name); setEmail(data.email ?? ""); }} className="btn-ghost px-3 py-1.5 text-xs">Cancelar</button>
          </>
        ) : (
          <button onClick={() => setEditing(true)} className="btn-ghost px-3 py-1.5 text-xs">Editar</button>
        )}
        {canGdpr ? (
          <>
            <button onClick={exportData} disabled={busy} className="btn-ghost px-3 py-1.5 text-xs">Exportar datos</button>
            <button onClick={anonymize} disabled={busy} className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300">Anonimizar</button>
          </>
        ) : null}
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
