"use client";

import { useCallback, useEffect, useState } from "react";
import {
  admin,
  type AdminLocation,
  type CashDay,
  type PaymentMethod,
} from "@/lib/admin";
import { formatPrice, formatTime } from "@/lib/format";

const METODOS: Array<{ value: PaymentMethod; label: string }> = [
  { value: "efectivo", label: "Efectivo" },
  { value: "tarjeta", label: "Tarjeta" },
  { value: "bono", label: "Bono" },
  { value: "regalo", label: "Tarjeta regalo" },
  { value: "online", label: "Pagado online" },
];

function hoy(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
}

export default function CajaPage() {
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [locationId, setLocationId] = useState<number | null>(null);
  const [date, setDate] = useState(hoy());
  const [data, setData] = useState<CashDay | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    admin
      .locations()
      .then((r) => {
        setLocations(r.locations);
        setLocationId((prev) => prev ?? r.locations[0]?.id ?? null);
      })
      .catch(() => setError("No se pudieron cargar las sedes."));
  }, []);

  const load = useCallback(async () => {
    if (!locationId) return;
    setLoading(true);
    setError(null);
    try {
      setData(await admin.cashDay(locationId, date));
    } catch (e) {
      setData(null);
      setError(e instanceof Error ? e.message : "No se pudo cargar la caja.");
    } finally {
      setLoading(false);
    }
  }, [locationId, date]);

  useEffect(() => {
    void load();
  }, [load]);

  async function cobrarPrepago(kind: "gift_card" | "pack", id: number, method: PaymentMethod | null) {
    try {
      await admin.setPrepaidPayment(kind, id, method);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo guardar la forma de pago.");
      await load();
    }
  }

  async function cobrar(appointmentId: number, method: PaymentMethod | null) {
    // Optimista: la fila cambia al instante y se recarga para recalcular totales.
    setData((d) =>
      d
        ? {
            ...d,
            appointments: d.appointments.map((a) =>
              a.appointment_id === appointmentId ? { ...a, payment_method: method } : a,
            ),
          }
        : d,
    );
    try {
      await admin.setAppointmentPayment(appointmentId, method);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo guardar la forma de pago.");
      await load();
    }
  }

  // Las horas se pintan en la zona de la sede (la caja es de una sede concreta).
  const zona = locations.find((l) => l.id === locationId)?.timezone ?? "Europe/Madrid";
  const pendientes = (data?.by_method.sin_registrar.count ?? 0) + (data?.prepaid_by_method.sin_registrar.count ?? 0);

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Caja</h1>
        <p className="mt-1 text-muted">Cómo se ha cobrado el día y arqueo al cerrar.</p>
      </header>

      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm font-semibold">
          Sede
          <select
            value={locationId ?? ""}
            onChange={(e) => setLocationId(Number(e.target.value))}
            className="field mt-1"
          >
            {locations.map((l) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </select>
        </label>
        <label className="text-sm font-semibold">
          Día
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="field mt-1" />
        </label>
      </div>

      {error ? <p className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p> : null}

      {loading ? (
        <div className="card h-64 animate-pulse" />
      ) : !data ? null : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <Total label="Total del día" value={formatPrice(data.total)} fuerte />
            {METODOS.map((m) => (
              <Total key={m.value} label={m.label} value={formatPrice(data.by_method[m.value].amount)} />
            ))}
          </div>

          {pendientes > 0 ? (
            <p className="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
              {pendientes === 1
                ? "Queda 1 cobro sin forma de pago: elígela abajo para poder cuadrar."
                : `Quedan ${pendientes} cobros sin forma de pago: elígelas abajo para poder cuadrar.`}
            </p>
          ) : null}

          <section className="card p-5">
            <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Servicios cobrados</h2>
            {data.appointments.length === 0 ? (
              <p className="text-sm text-muted">Ninguna cita completada este día.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <tbody>
                    {data.appointments.map((a) => (
                      <tr key={a.appointment_id} className="border-b border-border/50 last:border-0">
                        <td className="py-2 pr-3 tabular-nums text-muted">{formatTime(a.start, zona)}</td>
                        <td className="py-2 pr-3">
                          <p className="font-medium">{a.customer_name ?? "Sin cliente"}</p>
                          <p className="text-xs text-muted">
                            {a.service_name}
                            {a.staff_name ? ` · ${a.staff_name}` : ""}
                          </p>
                        </td>
                        <td className="py-2 pr-3 text-right font-semibold tabular-nums">{formatPrice(a.amount)}</td>
                        <td className="py-2">
                          <select
                            value={a.payment_method ?? ""}
                            onChange={(e) => cobrar(a.appointment_id, (e.target.value || null) as PaymentMethod | null)}
                            aria-label={`Forma de pago de ${a.customer_name ?? "la cita"}`}
                            className={
                              "rounded-lg border bg-card px-2 py-1.5 text-sm " +
                              (a.payment_method === null ? "border-amber-400" : "border-border")
                            }
                          >
                            <option value="">Sin cobrar</option>
                            {METODOS.map((m) => (
                              <option key={m.value} value={m.value}>{m.label}</option>
                            ))}
                          </select>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <Arqueo data={data} onClosed={load} locationId={locationId!} date={date} />

          {data.prepaid.length > 0 ? (
            <section className="card p-5">
              <h2 className="mb-1 text-sm font-semibold uppercase tracking-wide text-muted">
                Bonos y tarjetas regalo vendidos ({formatPrice(data.prepaid_total)})
              </h2>
              <p className="mb-3 text-sm text-muted">
                Se cobran al venderlos, así que lo pagado en efectivo también está en el cajón.
              </p>
              <table className="w-full text-sm">
                <tbody>
                  {data.prepaid.map((p) => (
                    <tr key={`${p.kind}-${p.id}`} className="border-b border-border/50 last:border-0">
                      <td className="py-2 pr-3 font-medium">{p.label}</td>
                      <td className="py-2 pr-3 text-right font-semibold tabular-nums">{formatPrice(p.amount)}</td>
                      <td className="py-2">
                        <select
                          value={p.payment_method ?? ""}
                          onChange={(e) => cobrarPrepago(p.kind, p.id, (e.target.value || null) as PaymentMethod | null)}
                          aria-label={`Forma de pago de ${p.label}`}
                          className={
                            "rounded-lg border bg-card px-2 py-1.5 text-sm " +
                            (p.payment_method === null ? "border-amber-400" : "border-border")
                          }
                        >
                          <option value="">Sin registrar</option>
                          {METODOS.filter((m) => m.value !== "bono" && m.value !== "regalo").map((m) => (
                            <option key={m.value} value={m.value}>{m.label}</option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          ) : null}
        </>
      )}
    </div>
  );
}

/** Arqueo: efectivo esperado frente a lo contado en el cajón. */
function Arqueo({
  data,
  locationId,
  date,
  onClosed,
}: {
  data: CashDay;
  locationId: number;
  date: string;
  onClosed: () => Promise<void>;
}) {
  const [counted, setCounted] = useState("");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  useEffect(() => {
    setCounted(data.close ? String(data.close.counted_cash) : "");
    setNotes(data.close?.notes ?? "");
    setMsg(null);
  }, [data.close, data.date]);

  const contado = Number(counted.trim().replace(",", "."));
  const valido = counted.trim() !== "" && Number.isFinite(contado) && contado >= 0;
  const diferencia = valido ? Math.round((contado - data.expected_cash) * 100) / 100 : null;

  async function cerrar() {
    if (!valido) {
      setMsg("Escribe cuánto efectivo hay contado.");
      return;
    }
    setSaving(true);
    setMsg(null);
    try {
      await admin.closeCash(locationId, date, contado, notes.trim() || null);
      await onClosed();
      setMsg("Caja cerrada.");
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "No se pudo cerrar la caja.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="card space-y-4 p-5">
      <div>
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted">Arqueo</h2>
        <p className="mt-2 text-sm text-muted">
          En el cajón debería haber{" "}
          <span className="font-semibold text-foreground">{formatPrice(data.expected_cash)}</span>: lo cobrado en
          efectivo, servicios y prepagos vendidos hoy.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm font-semibold">
          Efectivo contado
          <input
            value={counted}
            onChange={(e) => setCounted(e.target.value)}
            inputMode="decimal"
            placeholder="0,00"
            className="field mt-1 w-36"
          />
        </label>
        <label className="min-w-48 flex-1 text-sm font-semibold">
          Observaciones
          <input
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="opcional"
            className="field mt-1"
          />
        </label>
        <button onClick={cerrar} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Cerrando…" : data.close ? "Volver a cerrar" : "Cerrar caja"}
        </button>
      </div>

      {diferencia !== null ? (
        <p
          className={
            "text-sm font-medium " +
            (diferencia === 0 ? "text-emerald-700" : diferencia > 0 ? "text-amber-700" : "text-red-700")
          }
        >
          {diferencia === 0
            ? "Cuadra."
            : diferencia > 0
              ? `Sobran ${formatPrice(diferencia)}.`
              : `Faltan ${formatPrice(Math.abs(diferencia))}.`}
        </p>
      ) : null}

      {data.close ? (
        <p className="text-xs text-muted">
          Cerrada el {new Date(data.close.closed_at).toLocaleString("es-ES")}
          {data.close.closed_by_name ? ` por ${data.close.closed_by_name}` : ""} · diferencia registrada{" "}
          {formatPrice(data.close.difference)}.
        </p>
      ) : null}

      {msg ? <p className="text-sm text-muted">{msg}</p> : null}
    </section>
  );
}

function Total({ label, value, fuerte }: { label: string; value: string; fuerte?: boolean }) {
  return (
    <div className={"card p-4 " + (fuerte ? "border-[var(--brand)]" : "")}>
      <p className="text-xl font-bold tabular-nums">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}
