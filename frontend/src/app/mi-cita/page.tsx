"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import { formatDateLong, formatTime, isoDate } from "@/lib/format";
import type { LookupAppointment, LookupResult, Slot } from "@/lib/types";

export default function MiCitaPage() {
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [result, setResult] = useState<LookupResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function doLookup() {
    setLoading(true);
    setError(null);
    setResult(null);
    try {
      setResult(await api.lookup(phone.trim(), code.trim()));
    } catch (err) {
      setError(err instanceof Error ? err.message : "No encontramos tu cita.");
    } finally {
      setLoading(false);
    }
  }

  function lookup(e: React.FormEvent) {
    e.preventDefault();
    void doLookup();
  }

  function refresh() {
    void doLookup();
  }

  return (
    <div className="space-y-6">
      <section>
        <h1 className="text-2xl font-semibold tracking-tight">Mi cita</h1>
        <p className="mt-1 text-muted">Consulta, cambia o cancela tu cita con tu teléfono y tu código.</p>
      </section>

      <form onSubmit={lookup} className="space-y-4 rounded-[var(--radius-brand)] border border-border bg-card p-5">
        <label className="block text-sm font-medium">
          Teléfono
          <input
            type="tel"
            value={phone}
            required
            onChange={(e) => setPhone(e.target.value)}
            placeholder="+34 600 000 000"
            className="mt-1 block w-full rounded-[var(--radius-brand)] border border-border px-3 py-2 font-normal"
          />
        </label>
        <label className="block text-sm font-medium">
          Código de cita
          <input
            value={code}
            required
            onChange={(e) => setCode(e.target.value)}
            placeholder="el que te dimos al reservar"
            className="mt-1 block w-full rounded-[var(--radius-brand)] border border-border px-3 py-2 font-normal font-mono"
          />
        </label>
        <button
          type="submit"
          disabled={loading}
          className="w-full rounded-full bg-brand px-5 py-2.5 font-medium text-brand-ink disabled:opacity-50"
        >
          {loading ? "Buscando…" : "Buscar mi cita"}
        </button>
      </form>

      {error ? <p className="rounded-[var(--radius-brand)] bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p> : null}

      {result ? (
        result.appointments.length === 0 ? (
          <p className="rounded-[var(--radius-brand)] border border-border bg-card p-5 text-sm text-muted">
            Hola {result.customer.name}, no tienes próximas citas.
          </p>
        ) : (
          <ul className="space-y-3">
            {result.appointments.map((appt) => (
              <AppointmentCard key={appt.appointment_id} appt={appt} onChanged={refresh} />
            ))}
          </ul>
        )
      ) : null}
    </div>
  );
}

function AppointmentCard({ appt, onChanged }: { appt: LookupAppointment; onChanged: () => void }) {
  const tz = appt.location.timezone;
  const [mode, setMode] = useState<"view" | "reschedule">("view");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  async function cancel() {
    if (!confirm("¿Seguro que quieres cancelar esta cita?")) return;
    setBusy(true);
    setMsg(null);
    try {
      await api.cancel(appt.appointment_id, appt.public_code);
      onChanged();
    } catch (err) {
      setMsg(err instanceof Error ? err.message : "No se pudo cancelar.");
      setBusy(false);
    }
  }

  return (
    <li className="rounded-[var(--radius-brand)] border border-border bg-card p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="font-medium">{capitalize(formatDateLong(appt.start, tz))}</p>
          <p className="text-lg font-semibold">{formatTime(appt.start, tz)} h</p>
          <p className="mt-1 text-sm text-muted">
            {appt.service.name} · {appt.location.name}
            {appt.staff ? ` · ${appt.staff.name}` : ""}
          </p>
        </div>
        <span className="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-medium capitalize">{appt.status}</span>
      </div>

      {msg ? <p className="mt-3 text-sm text-red-700">{msg}</p> : null}

      {mode === "view" ? (
        <div className="mt-4 flex gap-2">
          <button
            onClick={() => setMode("reschedule")}
            className="rounded-full border border-border px-4 py-2 text-sm font-medium hover:border-brand"
          >
            Reprogramar
          </button>
          <button
            onClick={cancel}
            disabled={busy}
            className="rounded-full border border-border px-4 py-2 text-sm font-medium text-red-700 hover:border-red-300 disabled:opacity-50"
          >
            Cancelar
          </button>
        </div>
      ) : (
        <Reschedule
          appt={appt}
          onCancel={() => setMode("view")}
          onDone={onChanged}
        />
      )}
    </li>
  );
}

function Reschedule({
  appt,
  onCancel,
  onDone,
}: {
  appt: LookupAppointment;
  onCancel: () => void;
  onDone: () => void;
}) {
  const tz = appt.location.timezone;
  const [date, setDate] = useState(isoDate(new Date()));
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  async function loadSlots(d: string) {
    setLoading(true);
    setSlots(null);
    setMsg(null);
    try {
      const av = await api.availability({
        location_id: appt.location.id,
        service_id: appt.service.id,
        date: d,
      });
      setSlots(av.slots);
    } catch {
      setSlots([]);
    } finally {
      setLoading(false);
    }
  }

  async function pick(start: string) {
    setBusy(true);
    setMsg(null);
    try {
      await api.reschedule(appt.appointment_id, start, appt.public_code);
      onDone();
    } catch (err) {
      if (err instanceof ApiError && err.code === "SLOT_TAKEN") {
        setMsg("Ese hueco se acaba de ocupar. Elige otro.");
        void loadSlots(date);
      } else {
        setMsg(err instanceof Error ? err.message : "No se pudo reprogramar.");
      }
      setBusy(false);
    }
  }

  return (
    <div className="mt-4 space-y-3 border-t border-border pt-4">
      <label className="block text-sm font-medium">
        Nuevo día
        <input
          type="date"
          value={date}
          min={isoDate(new Date())}
          onChange={(e) => {
            setDate(e.target.value);
            void loadSlots(e.target.value);
          }}
          className="mt-1 block w-full rounded-[var(--radius-brand)] border border-border px-3 py-2"
        />
      </label>

      {msg ? <p className="text-sm text-red-700">{msg}</p> : null}

      {loading ? (
        <p className="text-sm text-muted">Buscando huecos…</p>
      ) : slots === null ? (
        <p className="text-sm text-muted">Elige un día para ver los huecos.</p>
      ) : slots.length === 0 ? (
        <p className="text-sm text-muted">No quedan huecos ese día. Prueba otra fecha.</p>
      ) : (
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
          {slots.map((s) => (
            <button
              key={s.start}
              disabled={busy}
              onClick={() => pick(s.start)}
              className="rounded-[var(--radius-brand)] border border-border py-2 text-sm font-medium hover:border-brand hover:bg-brand-soft disabled:opacity-50"
            >
              {formatTime(s.start, tz)}
            </button>
          ))}
        </div>
      )}

      <button onClick={onCancel} className="text-sm text-muted underline">
        Volver
      </button>
    </div>
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
