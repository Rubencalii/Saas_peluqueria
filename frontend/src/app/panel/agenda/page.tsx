"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  admin,
  AdminApiError,
  type Agenda,
  type AdminLocation,
  type AdminService,
  type AgendaAppointment,
  type StaffNextSlot,
} from "@/lib/admin";
import { formatDateLong, formatTime, isoDate } from "@/lib/format";

/** Datos para abrir "Nueva cita" ya rellenada desde "Próximo hueco". */
type NewApptPrefill = {
  serviceId: number;
  date: string;
  staffName: string;
  slot: { start: string; staff_id: number };
};

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
  const [services, setServices] = useState<AdminService[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [prefill, setPrefill] = useState<NewApptPrefill | null>(null);
  const [nextOpen, setNextOpen] = useState(false);
  const [view, setView] = useState<"day" | "week">("day");

  useEffect(() => {
    admin
      .locations()
      .then((r) => {
        setLocations(r.locations);
        if (r.locations.length > 0) setLocationId(r.locations[0].id);
      })
      .catch(() => setError("No se pudieron cargar las sedes."));
    admin
      .services()
      .then((r) => setServices(r.services))
      .catch(() => {});
  }, []);

  const load = useCallback(async () => {
    if (!locationId) return;
    setLoading(true);
    setError(null);
    try {
      setAgenda(await admin.agenda(locationId, date, view));
    } catch {
      setError("No se pudo cargar la agenda.");
      setAgenda(null);
    } finally {
      setLoading(false);
    }
  }, [locationId, date, view]);

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
        <div className="flex items-center gap-2">
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
          <button onClick={() => setNextOpen((v) => !v)} disabled={!locationId} className="btn-ghost px-4 py-2.5">
            🔎 Próximo hueco
          </button>
          <button
            onClick={() => { setPrefill(null); setCreating(true); }}
            disabled={!locationId}
            className="btn-primary px-4 py-2.5"
          >
            + Nueva cita
          </button>
        </div>
      </header>

      {nextOpen && locationId ? (
        <NextSlotsPanel
          locationId={locationId}
          fromDate={date}
          tz={tz}
          services={services.filter((s) => s.active && s.locations.some((o) => o.location_id === locationId))}
          onReserve={(p) => { setNextOpen(false); setPrefill(p); setCreating(true); }}
          onClose={() => setNextOpen(false)}
        />
      ) : null}

      {creating && locationId ? (
        <NewAppointment
          locationId={locationId}
          date={date}
          tz={tz}
          prefill={prefill}
          services={services.filter((s) => s.active && s.locations.some((o) => o.location_id === locationId))}
          onClose={() => { setCreating(false); setPrefill(null); }}
          onCreated={async (d) => {
            setCreating(false);
            setPrefill(null);
            setDate(d);
            await load();
          }}
        />
      ) : null}

      <div className="flex flex-wrap items-center gap-2">
        <button onClick={() => shiftDay(view === "week" ? -7 : -1)} className="btn-ghost px-3 py-2">←</button>
        <input
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          className="rounded-xl border border-border bg-card px-3 py-2 text-sm"
        />
        <button onClick={() => shiftDay(view === "week" ? 7 : 1)} className="btn-ghost px-3 py-2">→</button>
        <button onClick={() => setDate(isoDate(new Date()))} className="btn-ghost px-3 py-2 text-sm">Hoy</button>
        <div className="ml-auto flex rounded-full border border-border bg-card p-0.5 text-sm">
          <button
            onClick={() => setView("day")}
            className={"rounded-full px-3 py-1 font-medium " + (view === "day" ? "bg-brand text-brand-ink" : "text-muted")}
          >
            Día
          </button>
          <button
            onClick={() => setView("week")}
            className={"rounded-full px-3 py-1 font-medium " + (view === "week" ? "bg-brand text-brand-ink" : "text-muted")}
          >
            Semana
          </button>
        </div>
      </div>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-2xl bg-brand-soft/60" />
          ))}
        </div>
      ) : !agenda ? null : view === "week" ? (
        <WeekView agenda={agenda} tz={tz} onChanged={load} />
      ) : agenda.appointments.length > 0 ? (
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

function WeekView({ agenda, tz, onChanged }: { agenda: Agenda; tz: string; onChanged: () => void }) {
  const dayKey = (iso: string) => new Intl.DateTimeFormat("en-CA", { timeZone: tz }).format(new Date(iso));
  const dayLabel = (iso: string) =>
    new Intl.DateTimeFormat("es-ES", { weekday: "long", day: "numeric", month: "short", timeZone: tz }).format(new Date(iso));

  // 7 días desde el inicio de la semana (agenda.from, UTC ISO).
  const startMs = new Date(agenda.from).getTime();
  const days = Array.from({ length: 7 }, (_, i) => new Date(startMs + i * 86400000).toISOString());

  return (
    <div className="space-y-4">
      {days.map((dIso) => {
        const key = dayKey(dIso);
        const appts = agenda.appointments.filter((a) => dayKey(a.start) === key);
        return (
          <section key={key}>
            <h3 className="mb-2 text-sm font-semibold capitalize text-muted">{dayLabel(dIso)}</h3>
            {appts.length === 0 ? (
              <p className="rounded-xl bg-brand-soft/40 px-3 py-2 text-xs text-muted">Sin citas</p>
            ) : (
              <ul className="space-y-2">
                {appts.map((a) => (
                  <AppointmentRow key={a.appointment_id} appt={a} tz={tz} onChanged={onChanged} />
                ))}
              </ul>
            )}
          </section>
        );
      })}
    </div>
  );
}

function NextSlotsPanel({
  locationId,
  fromDate,
  tz,
  services,
  onReserve,
  onClose,
}: {
  locationId: number;
  fromDate: string;
  tz: string;
  services: AdminService[];
  onReserve: (p: NewApptPrefill) => void;
  onClose: () => void;
}) {
  const [serviceId, setServiceId] = useState<number | "">(services[0]?.id ?? "");
  const [rows, setRows] = useState<StaffNextSlot[] | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (serviceId === "") {
      setRows(null);
      return;
    }
    setLoading(true);
    admin
      .nextSlotsByStaff(locationId, Number(serviceId), fromDate)
      .then((r) => setRows(r.staff))
      .catch(() => setRows([]))
      .finally(() => setLoading(false));
  }, [serviceId, locationId, fromDate]);

  return (
    <div className="card space-y-3 p-4">
      <div className="flex items-center justify-between">
        <h2 className="font-semibold">Próximo hueco libre por profesional</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>
      <select
        value={serviceId}
        onChange={(e) => setServiceId(e.target.value === "" ? "" : Number(e.target.value))}
        className="rounded-xl border border-border bg-card px-3 py-2 text-sm"
      >
        <option value="">Elige un servicio…</option>
        {services.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name}
          </option>
        ))}
      </select>

      {serviceId === "" ? null : loading ? (
        <p className="text-sm text-muted">Buscando huecos…</p>
      ) : rows && rows.length > 0 ? (
        <ul className="space-y-2">
          {rows.map((r) => (
            <li key={r.staff_id} className="flex items-center justify-between gap-3 rounded-xl bg-brand-soft/50 px-3 py-2 text-sm">
              <span className="font-medium">{r.staff_name}</span>
              {r.next ? (
                <div className="flex items-center gap-3">
                  <span className="text-right text-muted">
                    <span className="capitalize">{formatDateLong(r.next.start, tz)}</span> · {formatTime(r.next.start, tz)} h
                  </span>
                  <button
                    onClick={() =>
                      onReserve({
                        serviceId: Number(serviceId),
                        date: r.next!.date,
                        staffName: r.staff_name,
                        slot: { start: r.next!.start, staff_id: r.staff_id },
                      })
                    }
                    className="btn-primary shrink-0 px-3 py-1.5 text-xs"
                  >
                    Reservar
                  </button>
                </div>
              ) : (
                <span className="text-muted">Sin huecos próximos</span>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted">Nadie ofrece este servicio en esta sede.</p>
      )}
    </div>
  );
}

function NewAppointment({
  locationId,
  date: agendaDate,
  tz,
  services,
  prefill,
  onClose,
  onCreated,
}: {
  locationId: number;
  date: string;
  tz: string;
  services: AdminService[];
  prefill: NewApptPrefill | null;
  onClose: () => void;
  onCreated: (date: string) => void;
}) {
  const [serviceId, setServiceId] = useState<number | "">(prefill?.serviceId ?? "");
  const [date, setDate] = useState(prefill?.date ?? agendaDate);
  const [slots, setSlots] = useState<Array<{ start: string; staff_id: number }> | null>(null);
  const [loadingSlots, setLoadingSlots] = useState(false);
  const [slot, setSlot] = useState<{ start: string; staff_id: number } | null>(null);
  // El prefill viene de "Próximo hueco": preselecciona el hueco una sola vez,
  // cuando llega la disponibilidad del día (el efecto resetea slot al cargar).
  const prefillApplied = useRef(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (serviceId === "") {
      setSlots(null);
      return;
    }
    setLoadingSlots(true);
    setSlot(null);
    admin
      .availability(locationId, Number(serviceId), date)
      .then((r) => {
        setSlots(r.slots);
        if (
          prefill &&
          !prefillApplied.current &&
          date === prefill.date &&
          Number(serviceId) === prefill.serviceId
        ) {
          prefillApplied.current = true;
          // Sólo si el hueco sigue libre (puede haberse ocupado entretanto).
          if (r.slots.some((s) => s.start === prefill.slot.start)) {
            setSlot(prefill.slot);
          }
        }
      })
      .catch(() => setSlots([]))
      .finally(() => setLoadingSlots(false));
  }, [serviceId, date, locationId, prefill]);

  async function submit() {
    if (serviceId === "" || !slot || name.trim() === "" || phone.trim() === "") {
      setError("Completa servicio, hueco, nombre y teléfono.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await admin.createAppointment({
        location_id: locationId,
        service_id: Number(serviceId),
        staff_id: slot.staff_id || null,
        start: slot.start,
        customer: { name: name.trim(), phone: phone.trim(), email: email.trim() || null },
      });
      onCreated(date);
    } catch (e) {
      if (e instanceof AdminApiError && e.code === "SLOT_TAKEN") {
        setError("Ese hueco se acaba de ocupar. Elige otro.");
        setSlot(null);
        admin.availability(locationId, Number(serviceId), date).then((r) => setSlots(r.slots)).catch(() => {});
      } else {
        setError(e instanceof Error ? e.message : "No se pudo crear la cita.");
      }
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Nueva cita</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      {prefill && Number(serviceId) === prefill.serviceId && date === prefill.date ? (
        <div className="rounded-xl border border-[var(--brand)] bg-brand-soft/50 px-3 py-2 text-sm">
          💡 Hueco sugerido para <span className="font-medium">{prefill.staffName}</span>:{" "}
          <span className="capitalize">{formatDateLong(prefill.slot.start, tz)}</span> ·{" "}
          {formatTime(prefill.slot.start, tz)} h. Solo falta indicar el cliente.
        </div>
      ) : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm font-semibold">
          Servicio
          <select
            value={serviceId}
            onChange={(e) => setServiceId(e.target.value === "" ? "" : Number(e.target.value))}
            className="field"
          >
            <option value="">Elige un servicio…</option>
            {services.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name} ({s.duration_min} min)
              </option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Día
          <input type="date" value={date} min={isoDate(new Date())} onChange={(e) => setDate(e.target.value)} className="field" />
        </label>
      </div>

      {serviceId !== "" ? (
        <div>
          <p className="mb-2 text-sm font-semibold">Hueco</p>
          {loadingSlots ? (
            <p className="text-sm text-muted">Buscando…</p>
          ) : slots && slots.length > 0 ? (
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
              {slots.map((s) => (
                <button
                  key={s.start}
                  onClick={() => setSlot(s)}
                  className={"slot " + (slot?.start === s.start ? "border-[var(--brand)] bg-brand-soft" : "")}
                >
                  {formatTime(s.start, tz)}
                </button>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted">No quedan huecos ese día.</p>
          )}
        </div>
      ) : null}

      <div className="grid gap-3 sm:grid-cols-3">
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nombre del cliente" className="field" />
        <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Teléfono" className="field" />
        <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email (opcional)" className="field" />
      </div>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="btn-ghost">Cancelar</button>
        <button onClick={submit} disabled={saving || !slot} className="btn-primary px-5 py-2.5">
          {saving ? "Creando…" : "Crear cita"}
        </button>
      </div>
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
