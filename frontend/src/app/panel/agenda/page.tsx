"use client";

import { useCallback, useEffect, useState } from "react";
import {
  admin,
  type Agenda,
  type AdminLocation,
  type AgendaAppointment,
} from "@/lib/admin";
import { formatTime, isoDate } from "@/lib/format";

const STATUS: Record<string, { label: string; cls: string }> = {
  pendiente: { label: "Pendiente", cls: "bg-amber-100 text-amber-800" },
  confirmada: { label: "Confirmada", cls: "bg-emerald-100 text-emerald-800" },
  completada: { label: "Completada", cls: "bg-sky-100 text-sky-800" },
  no_show: { label: "No-show", cls: "bg-red-100 text-red-700" },
  cancelada: { label: "Cancelada", cls: "bg-zinc-200 text-zinc-600" },
};

export default function AgendaPage() {
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [locationId, setLocationId] = useState<number | null>(null);
  const [date, setDate] = useState(isoDate(new Date()));
  const [agenda, setAgenda] = useState<Agenda | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    admin
      .locations()
      .then((r) => {
        setLocations(r.locations);
        if (r.locations.length > 0) setLocationId(r.locations[0].id);
      })
      .catch(() => setError("No se pudieron cargar las sedes."));
  }, []);

  const load = useCallback(async () => {
    if (!locationId) return;
    setLoading(true);
    setError(null);
    try {
      setAgenda(await admin.agenda(locationId, date, "day"));
    } catch {
      setError("No se pudo cargar la agenda.");
      setAgenda(null);
    } finally {
      setLoading(false);
    }
  }, [locationId, date]);

  useEffect(() => {
    void load();
  }, [load]);

  function shiftDay(days: number) {
    const d = new Date(date + "T12:00:00");
    d.setDate(d.getDate() + days);
    setDate(isoDate(d));
  }

  const tz = agenda?.location.timezone ?? "Europe/Madrid";

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Agenda</h1>
        {locations.length > 1 ? (
          <select
            value={locationId ?? ""}
            onChange={(e) => setLocationId(Number(e.target.value))}
            className="rounded-xl border border-border bg-card px-3 py-2 text-sm"
          >
            {locations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </select>
        ) : null}
      </header>

      <div className="flex items-center gap-2">
        <button onClick={() => shiftDay(-1)} className="btn-ghost px-3 py-2">←</button>
        <input
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          className="rounded-xl border border-border bg-card px-3 py-2 text-sm"
        />
        <button onClick={() => shiftDay(1)} className="btn-ghost px-3 py-2">→</button>
        <button onClick={() => setDate(isoDate(new Date()))} className="btn-ghost px-3 py-2 text-sm">
          Hoy
        </button>
      </div>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-2xl bg-brand-soft/60" />
          ))}
        </div>
      ) : agenda && agenda.appointments.length > 0 ? (
        <ul className="space-y-2">
          {agenda.appointments.map((a) => (
            <AppointmentRow key={a.appointment_id} appt={a} tz={tz} onChanged={load} />
          ))}
        </ul>
      ) : (
        <p className="card p-6 text-center text-sm text-muted">No hay citas este día. 🙌</p>
      )}
    </div>
  );
}

function AppointmentRow({
  appt,
  tz,
  onChanged,
}: {
  appt: AgendaAppointment;
  tz: string;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const st = STATUS[appt.status] ?? { label: appt.status, cls: "bg-zinc-200 text-zinc-600" };
  const canClose = appt.status === "pendiente" || appt.status === "confirmada";

  async function act(fn: () => Promise<unknown>) {
    setBusy(true);
    try {
      await fn();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <li className="card flex flex-wrap items-center gap-3 p-4">
      <div className="w-16 shrink-0">
        <p className="text-lg font-bold leading-none">{formatTime(appt.start, tz)}</p>
        <p className="mt-1 text-xs text-muted">{appt.service.duration_min} min</p>
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium">
          {appt.customer ? appt.customer.name : "Sin cliente"}
          {appt.staff ? <span className="text-muted"> · {appt.staff.name}</span> : null}
        </p>
        <p className="truncate text-sm text-muted">
          {appt.service.name}
          {appt.customer ? ` · ${appt.customer.phone}` : ""}
        </p>
      </div>
      <span className={"chip " + st.cls}>{st.label}</span>

      {appt.status !== "cancelada" ? (
        <div className="flex w-full gap-2 sm:w-auto">
          {canClose ? (
            <>
              <button
                disabled={busy}
                onClick={() => act(() => admin.setAppointmentStatus(appt.appointment_id, "completada"))}
                className="btn-ghost px-3 py-1.5 text-xs"
              >
                Completada
              </button>
              <button
                disabled={busy}
                onClick={() => act(() => admin.setAppointmentStatus(appt.appointment_id, "no_show"))}
                className="btn-ghost px-3 py-1.5 text-xs"
              >
                No-show
              </button>
            </>
          ) : null}
          <button
            disabled={busy}
            onClick={() => {
              if (confirm("¿Cancelar esta cita?")) void act(() => admin.cancelAppointment(appt.appointment_id));
            }}
            className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300"
          >
            Cancelar
          </button>
        </div>
      ) : null}
    </li>
  );
}
