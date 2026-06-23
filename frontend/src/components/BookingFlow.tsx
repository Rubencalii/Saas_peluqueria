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
      <div className="card overflow-hidden p-8 text-center">
        <div
          className="mx-auto grid h-16 w-16 place-items-center rounded-full text-3xl text-white"
          style={{ background: "var(--accent)" }}
        >
          ✓
        </div>
        <h2 className="mt-4 text-2xl font-bold">¡Cita confirmada!</h2>
        <p className="mt-1 text-muted">{capitalize(formatDateLong(result.start, timeZone))}</p>
        <p className="text-xl font-semibold">{formatTime(result.start, timeZone)} h</p>
        <div className="mx-auto mt-5 max-w-sm rounded-2xl bg-brand-soft p-4 text-sm">
          Tu código de cita es{" "}
          <span className="font-mono text-base font-bold tracking-wider">{result.public_code}</span>
          <p className="mt-1 text-muted">Lo necesitarás para cambiarla o cancelarla en «Mi cita».</p>
        </div>
        <div className="mt-6 flex justify-center gap-2">
          <a href="/mi-cita" className="btn-ghost">Ver mi cita</a>
          <a href="/" className="btn-primary">Hecho</a>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <Steps current={step} />

      {error && step !== "done" ? (
        <p className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </p>
      ) : null}

      {step === "service" && (
        <ul className="grid gap-3">
          {services.map((s) => (
            <li key={s.id}>
              <button onClick={() => chooseService(s)} className="card-link group w-full p-4 text-left">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <span className="grid h-10 w-10 place-items-center rounded-full bg-brand-soft text-lg">
                      ✂️
                    </span>
                    <div>
                      <p className="font-semibold">{s.name}</p>
                      <p className="text-sm text-muted">
                        {s.duration_min} min{s.description ? ` · ${s.description}` : ""}
                      </p>
                    </div>
                  </div>
                  {s.price !== null ? (
                    <span className="shrink-0 font-semibold">{formatPrice(s.price)}</span>
                  ) : null}
                </div>
              </button>
            </li>
          ))}
        </ul>
      )}

      {step === "datetime" && service && (
        <div className="space-y-5">
          <SelectedBadge label={service.name} onChange={() => setStep("service")} />

          <label className="block text-sm font-semibold">
            Elige el día
            <input
              type="date"
              value={date}
              min={isoDate(new Date())}
              onChange={(e) => setDate(e.target.value)}
              className="field"
            />
          </label>

          <div>
            <p className="mb-2 text-sm font-semibold">Horas disponibles</p>
            {loadingSlots ? (
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div key={i} className="h-10 animate-pulse rounded-2xl bg-brand-soft" />
                ))}
              </div>
            ) : slots && slots.length > 0 ? (
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                {slots.map((s) => (
                  <button key={s.start} onClick={() => chooseSlot(s)} className="slot">
                    {formatTime(s.start, timeZone)}
                  </button>
                ))}
              </div>
            ) : (
              <p className="rounded-2xl border border-border bg-card p-4 text-sm text-muted">
                No quedan huecos ese día. Prueba con otra fecha. 📅
              </p>
            )}
          </div>
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

          <label className="flex items-start gap-3 rounded-2xl bg-brand-soft/60 p-3 text-sm">
            <input
              type="checkbox"
              checked={waConsent}
              onChange={(e) => setWaConsent(e.target.checked)}
              className="mt-0.5 h-4 w-4 accent-[var(--brand)]"
            />
            <span>Quiero recibir la confirmación y el recordatorio por WhatsApp 💬</span>
          </label>

          <button
            type="submit"
            disabled={submitting || name.trim() === "" || phone.trim() === ""}
            className="btn-primary w-full py-3.5"
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
    <ol className="flex items-center">
      {items.map((it, i) => {
        const done = i < idx;
        const active = i === idx;
        return (
          <li key={it.key} className="flex flex-1 items-center last:flex-none">
            <div className="flex items-center gap-2">
              <span
                className={
                  "grid h-7 w-7 place-items-center rounded-full text-xs font-bold transition " +
                  (done || active ? "text-brand-ink" : "bg-brand-soft text-muted")
                }
                style={done || active ? { background: "var(--brand)" } : undefined}
              >
                {done ? "✓" : i + 1}
              </span>
              <span
                className={
                  "text-xs font-semibold " + (active ? "text-foreground" : "text-muted")
                }
              >
                {it.label}
              </span>
            </div>
            {i < items.length - 1 ? (
              <span
                className="mx-2 h-px flex-1 transition"
                style={{ background: done ? "var(--brand)" : "var(--border)" }}
              />
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

function SelectedBadge({ label, onChange }: { label: string; onChange: () => void }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-2xl bg-brand-soft px-4 py-2.5 text-sm">
      <span className="font-medium">{label}</span>
      <button type="button" onClick={onChange} className="shrink-0 font-semibold text-brand-strong underline">
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
    <label className="block text-sm font-semibold">
      {label}
      <input
        type={type}
        value={value}
        required={required}
        placeholder={placeholder}
        autoComplete={autoComplete}
        onChange={(e) => onChange(e.target.value)}
        className="field"
      />
    </label>
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
