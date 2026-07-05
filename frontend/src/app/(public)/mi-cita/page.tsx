"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import { formatDateLong, formatTime, isoDate } from "@/lib/format";
import { useLang } from "@/components/LangProvider";
import type { LookupAppointment, LookupResult, Slot } from "@/lib/types";

export default function MiCitaPage() {
  const { t } = useLang();
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
      setError(err instanceof Error ? err.message : t("my.notFound"));
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
        <h1 className="text-3xl font-bold tracking-tight">{t("my.title")}</h1>
        <p className="mt-1 text-muted">{t("my.subtitle")}</p>
      </section>

      <form onSubmit={lookup} className="card space-y-4 p-5">
        <label className="block text-sm font-semibold">
          {t("my.phone")}
          <input
            type="tel"
            value={phone}
            required
            onChange={(e) => setPhone(e.target.value)}
            placeholder="+34 600 000 000"
            className="field"
          />
        </label>
        <label className="block text-sm font-semibold">
          {t("my.code")}
          <input
            value={code}
            required
            onChange={(e) => setCode(e.target.value)}
            placeholder={t("my.codePlaceholder")}
            className="field font-mono"
          />
        </label>
        <button type="submit" disabled={loading} className="btn-primary w-full">
          {loading ? t("my.searching") : t("my.search")}
        </button>
      </form>

      {error ? (
        <p className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      ) : null}

      {result ? (
        result.appointments.length === 0 ? (
          <p className="card p-5 text-sm text-muted">{t("my.none", { name: result.customer.name })}</p>
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
  const { t, intl } = useLang();
  const tz = appt.location.timezone;
  const [mode, setMode] = useState<"view" | "reschedule">("view");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  async function cancel() {
    if (!confirm(t("my.cancelConfirm"))) return;
    setBusy(true);
    setMsg(null);
    try {
      await api.cancel(appt.appointment_id, appt.public_code);
      onChanged();
    } catch (err) {
      setMsg(err instanceof Error ? err.message : t("my.cancelError"));
      setBusy(false);
    }
  }

  return (
    <li className="card p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="font-medium">{capitalize(formatDateLong(appt.start, tz, intl))}</p>
          <p className="text-lg font-semibold">{formatTime(appt.start, tz, intl)}</p>
          <p className="mt-1 text-sm text-muted">
            {appt.service.name} · {appt.location.name}
            {appt.staff ? ` · ${appt.staff.name}` : ""}
          </p>
        </div>
        <span className="chip bg-brand-soft capitalize">{appt.status}</span>
      </div>

      {msg ? <p className="mt-3 text-sm text-red-700">{msg}</p> : null}

      {mode === "view" ? (
        <div className="mt-4 flex gap-2">
          <button onClick={() => setMode("reschedule")} className="btn-ghost">
            {t("my.reschedule")}
          </button>
          <button
            onClick={cancel}
            disabled={busy}
            className="btn-ghost text-red-700 hover:border-red-300"
          >
            {t("my.cancel")}
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
  const { t, intl } = useLang();
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
        setMsg(t("my.slotTaken"));
        void loadSlots(date);
      } else {
        setMsg(err instanceof Error ? err.message : t("my.rescheduleError"));
      }
      setBusy(false);
    }
  }

  return (
    <div className="mt-4 space-y-3 border-t border-border pt-4">
      <label className="block text-sm font-semibold">
        {t("my.newDay")}
        <input
          type="date"
          value={date}
          min={isoDate(new Date())}
          onChange={(e) => {
            setDate(e.target.value);
            void loadSlots(e.target.value);
          }}
          className="field"
        />
      </label>

      {msg ? <p className="text-sm text-red-700">{msg}</p> : null}

      {loading ? (
        <p className="text-sm text-muted">{t("my.searchingSlots")}</p>
      ) : slots === null ? (
        <p className="text-sm text-muted">{t("my.pickDay")}</p>
      ) : slots.length === 0 ? (
        <p className="text-sm text-muted">{t("my.noSlotsDay")}</p>
      ) : (
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
          {slots.map((s) => (
            <button key={s.start} disabled={busy} onClick={() => pick(s.start)} className="slot disabled:opacity-50">
              {formatTime(s.start, tz, intl)}
            </button>
          ))}
        </div>
      )}

      <button onClick={onCancel} className="text-sm text-muted underline">
        {t("my.back")}
      </button>
    </div>
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
