"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { formatDateLong, formatPrice, formatTime, isoDate } from "@/lib/format";
import type { AppointmentResult, Service, Slot } from "@/lib/types";

type Step = "service" | "datetime" | "customer" | "done";

export function BookingFlow({
  locationId,
  timeZone,
  services,
}: {
  locationId: number;
  timeZone: string;
  services: Service[];
}) {
  const [step, setStep] = useState<Step>("service");
  const [service, setService] = useState<Service | null>(null);
  const [date, setDate] = useState<string>(isoDate(new Date()));
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [loadingSlots, setLoadingSlots] = useState(false);
  const [slot, setSlot] = useState<Slot | null>(null);

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [waConsent, setWaConsent] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<AppointmentResult | null>(null);

  const loadSlots = useCallback(async () => {
    if (!service) return;
    setLoadingSlots(true);
    setSlots(null);
    setSlot(null);
    try {
      const av = await api.availability({ location_id: locationId, service_id: service.id, date });
      setSlots(av.slots);
    } catch {
      setSlots([]);
    } finally {
      setLoadingSlots(false);
    }
  }, [service, locationId, date]);

  useEffect(() => {
    if (step === "datetime" && service) void loadSlots();
  }, [step, service, date, loadSlots]);

  function chooseService(s: Service) {
    setService(s);
    setStep("datetime");
  }

  function chooseSlot(s: Slot) {
    setSlot(s);
    setError(null);
    setStep("customer");
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!service || !slot) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await api.createAppointment(
        {
          location_id: locationId,
          service_id: service.id,
          staff_id: slot.staff_id || null,
          start: slot.start,
          customer: { name: name.trim(), phone: phone.trim(), email: email.trim() || null },
          wa_consent: waConsent,
        },
        crypto.randomUUID(),
      );
      setResult(res);
      setStep("done");
    } catch (err) {
      if (err instanceof ApiError && err.code === "SLOT_TAKEN") {
        setError("Vaya, ese hueco se acaba de ocupar. Elige otro, por favor.");
        setStep("datetime");
        void loadSlots();
      } else {
        setError(err instanceof Error ? err.message : "No se pudo completar la reserva.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (step === "done" && result) {
    return (
      <div className="rounded-[var(--radius-brand)] border border-border bg-card p-6 text-center">
        <div className="text-4xl">✅</div>
        <h2 className="mt-2 text-xl font-semibold">¡Cita confirmada!</h2>
        <p className="mt-1 text-muted">{capitalize(formatDateLong(result.start, timeZone))}</p>
        <p className="text-lg font-medium">{formatTime(result.start, timeZone)} h</p>
        <div className="mt-4 rounded-[var(--radius-brand)] bg-brand-soft p-4 text-sm">
          Guarda tu código de cita: <span className="font-mono font-semibold">{result.public_code}</span>
          <br />
          Lo necesitarás para cambiarla o cancelarla en <strong>Mi cita</strong>.
        </div>
        <a
          href="/"
          className="mt-5 inline-block rounded-full bg-brand px-5 py-2.5 text-sm font-medium text-brand-ink"
        >
          Hecho
        </a>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <Steps current={step} />

      {error && step !== "done" ? (
        <p className="rounded-[var(--radius-brand)] bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      ) : null}

      {step === "service" && (
        <ul className="grid gap-3">
          {services.map((s) => (
            <li key={s.id}>
              <button
                onClick={() => chooseService(s)}
                className="flex w-full items-center justify-between rounded-[var(--radius-brand)] border border-border bg-card p-4 text-left transition-colors hover:border-brand"
              >
                <span>
                  <span className="font-medium">{s.name}</span>
                  <span className="block text-sm text-muted">{s.duration_min} min</span>
                </span>
                {s.price !== null ? <span className="font-medium">{formatPrice(s.price)}</span> : null}
              </button>
            </li>
          ))}
        </ul>
      )}

      {step === "datetime" && service && (
        <div className="space-y-4">
          <SelectedBadge label={service.name} onChange={() => setStep("service")} />

          <label className="block text-sm font-medium">
            Día
            <input
              type="date"
              value={date}
              min={isoDate(new Date())}
              onChange={(e) => setDate(e.target.value)}
              className="mt-1 block w-full rounded-[var(--radius-brand)] border border-border bg-card px-3 py-2"
            />
          </label>

          {loadingSlots ? (
            <p className="text-sm text-muted">Buscando huecos…</p>
          ) : slots && slots.length > 0 ? (
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
              {slots.map((s) => (
                <button
                  key={s.start}
                  onClick={() => chooseSlot(s)}
                  className="rounded-[var(--radius-brand)] border border-border bg-card py-2 text-sm font-medium transition-colors hover:border-brand hover:bg-brand-soft"
                >
                  {formatTime(s.start, timeZone)}
                </button>
              ))}
            </div>
          ) : (
            <p className="rounded-[var(--radius-brand)] border border-border bg-card p-4 text-sm text-muted">
              No quedan huecos ese día. Prueba con otra fecha.
            </p>
          )}
        </div>
      )}

      {step === "customer" && service && slot && (
        <form onSubmit={submit} className="space-y-4">
          <SelectedBadge
            label={`${service.name} · ${capitalize(formatDateLong(slot.start, timeZone))} · ${formatTime(slot.start, timeZone)} h`}
            onChange={() => setStep("datetime")}
          />

          <Field label="Nombre y apellidos" value={name} onChange={setName} required autoComplete="name" />
          <Field
            label="Teléfono"
            value={phone}
            onChange={setPhone}
            required
            type="tel"
            autoComplete="tel"
            placeholder="+34 600 000 000"
          />
          <Field label="Email (opcional)" value={email} onChange={setEmail} type="email" autoComplete="email" />

          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              checked={waConsent}
              onChange={(e) => setWaConsent(e.target.checked)}
              className="mt-0.5"
            />
            <span>Quiero recibir la confirmación y el recordatorio por WhatsApp.</span>
          </label>

          <button
            type="submit"
            disabled={submitting || name.trim() === "" || phone.trim() === ""}
            className="w-full rounded-full bg-brand px-5 py-3 font-medium text-brand-ink disabled:opacity-50"
          >
            {submitting ? "Reservando…" : "Confirmar cita"}
          </button>
        </form>
      )}
    </div>
  );
}

function Steps({ current }: { current: Step }) {
  const items: { key: Step; label: string }[] = [
    { key: "service", label: "Servicio" },
    { key: "datetime", label: "Día y hora" },
    { key: "customer", label: "Tus datos" },
  ];
  const order: Step[] = ["service", "datetime", "customer", "done"];
  const idx = order.indexOf(current);
  return (
    <ol className="flex items-center gap-2 text-xs">
      {items.map((it, i) => (
        <li key={it.key} className="flex items-center gap-2">
          <span
            className={
              "rounded-full px-2.5 py-1 font-medium " +
              (i <= idx ? "bg-brand text-brand-ink" : "bg-brand-soft text-muted")
            }
          >
            {i + 1}. {it.label}
          </span>
          {i < items.length - 1 ? <span className="text-border">—</span> : null}
        </li>
      ))}
    </ol>
  );
}

function SelectedBadge({ label, onChange }: { label: string; onChange: () => void }) {
  return (
    <div className="flex items-center justify-between rounded-[var(--radius-brand)] bg-brand-soft px-4 py-2 text-sm">
      <span className="font-medium">{label}</span>
      <button type="button" onClick={onChange} className="text-brand underline">
        Cambiar
      </button>
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  type = "text",
  required = false,
  placeholder,
  autoComplete,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  type?: string;
  required?: boolean;
  placeholder?: string;
  autoComplete?: string;
}) {
  return (
    <label className="block text-sm font-medium">
      {label}
      <input
        type={type}
        value={value}
        required={required}
        placeholder={placeholder}
        autoComplete={autoComplete}
        onChange={(e) => onChange(e.target.value)}
        className="mt-1 block w-full rounded-[var(--radius-brand)] border border-border bg-card px-3 py-2 font-normal"
      />
    </label>
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
