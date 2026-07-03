"use client";

import { useCallback, useEffect, useState } from "react";
import {
  admin,
  type AdminLocation,
  type AdminService,
  type AdminStaff,
  type RecurringItem,
} from "@/lib/admin";

const DAYS = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"];

export default function RecurrentesPage() {
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [locationId, setLocationId] = useState<number | null>(null);
  const [services, setServices] = useState<AdminService[]>([]);
  const [staff, setStaff] = useState<AdminStaff[]>([]);
  const [items, setItems] = useState<RecurringItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    admin
      .locations()
      .then((r) => {
        setLocations(r.locations);
        if (r.locations.length > 0) setLocationId(r.locations[0].id);
      })
      .catch(() => {});
    admin.services().then((r) => setServices(r.services)).catch(() => {});
    admin.staff().then((r) => setStaff(r.staff)).catch(() => {});
  }, []);

  const load = useCallback(async () => {
    if (!locationId) return;
    setLoading(true);
    try {
      setItems((await admin.recurring(locationId)).recurring);
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [locationId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function remove(id: number) {
    if (!confirm("¿Dar de baja esta cita recurrente? Las citas ya creadas se conservan.")) return;
    await admin.deleteRecurring(id);
    await load();
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Citas recurrentes</h1>
        <div className="flex items-center gap-2">
          {locations.length > 1 ? (
            <select
              value={locationId ?? ""}
              onChange={(e) => setLocationId(Number(e.target.value))}
              className="rounded-xl border border-border bg-card px-3 py-2 text-sm"
            >
              {locations.map((l) => (
                <option key={l.id} value={l.id}>{l.name}</option>
              ))}
            </select>
          ) : null}
          <button onClick={() => setCreating(true)} disabled={!locationId} className="btn-primary px-4 py-2.5">
            + Nueva recurrencia
          </button>
        </div>
      </header>
      <p className="text-sm text-muted">
        Clientes fijos (p. ej. corte cada 4 semanas). El sistema crea su próxima cita automáticamente
        cuando toca, si hay hueco.
      </p>

      {creating && locationId ? (
        <NewRecurring
          locationId={locationId}
          services={services.filter((s) => s.active && s.locations.some((o) => o.location_id === locationId))}
          staff={staff}
          onClose={() => setCreating(false)}
          onCreated={async () => {
            setCreating(false);
            await load();
          }}
        />
      ) : null}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-2xl bg-brand-soft/60" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">
          No hay citas recurrentes en esta sede. Crea la primera para tus clientes fijos. 🔁
        </p>
      ) : (
        <ul className="space-y-2">
          {items.map((r) => (
            <li key={r.id} className="card flex flex-wrap items-center justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="font-medium">
                  {r.customer.name} <span className="text-muted">· {r.customer.phone}</span>
                </p>
                <p className="mt-0.5 text-sm text-muted">
                  {r.service_name}
                  {r.staff_name ? ` · ${r.staff_name}` : ""} · {DAYS[r.weekday] ?? r.weekday} a las {r.time} · cada{" "}
                  {r.interval_weeks === 1 ? "semana" : `${r.interval_weeks} semanas`}
                </p>
                {r.last_generated_date ? (
                  <p className="text-xs text-muted">Última cita generada: {r.last_generated_date}</p>
                ) : null}
              </div>
              <button
                onClick={() => remove(r.id)}
                className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300"
              >
                Dar de baja
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function NewRecurring({
  locationId,
  services,
  staff,
  onClose,
  onCreated,
}: {
  locationId: number;
  services: AdminService[];
  staff: AdminStaff[];
  onClose: () => void;
  onCreated: () => void;
}) {
  const [serviceId, setServiceId] = useState<number | "">("");
  const [staffId, setStaffId] = useState<number | "">("");
  const [weekday, setWeekday] = useState(0);
  const [time, setTime] = useState("10:00");
  const [interval, setIntervalWeeks] = useState(4);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const eligibleStaff = staff.filter(
    (s) =>
      s.active &&
      s.location_ids.includes(locationId) &&
      (serviceId === "" || s.service_ids.includes(Number(serviceId))),
  );

  async function submit() {
    if (serviceId === "" || name.trim() === "" || phone.trim() === "") {
      setError("Completa servicio, nombre y teléfono.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await admin.createRecurring({
        location_id: locationId,
        service_id: Number(serviceId),
        staff_id: staffId === "" ? null : Number(staffId),
        customer: { name: name.trim(), phone: phone.trim() },
        weekday,
        time,
        interval_weeks: interval,
      });
      onCreated();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo crear la recurrencia.");
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Nueva recurrencia</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm font-semibold">
          Servicio
          <select
            value={serviceId}
            onChange={(e) => { setServiceId(e.target.value === "" ? "" : Number(e.target.value)); setStaffId(""); }}
            className="field"
          >
            <option value="">Elige un servicio…</option>
            {services.map((s) => (
              <option key={s.id} value={s.id}>{s.name} ({s.duration_min} min)</option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Profesional
          <select
            value={staffId}
            onChange={(e) => setStaffId(e.target.value === "" ? "" : Number(e.target.value))}
            className="field"
          >
            <option value="">Sin preferencia</option>
            {eligibleStaff.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <label className="block text-sm font-semibold">
          Día de la semana
          <select value={weekday} onChange={(e) => setWeekday(Number(e.target.value))} className="field">
            {DAYS.map((d, i) => (
              <option key={i} value={i}>{d}</option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Hora
          <input type="time" value={time} onChange={(e) => setTime(e.target.value)} className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Repetir cada
          <select value={interval} onChange={(e) => setIntervalWeeks(Number(e.target.value))} className="field">
            {[1, 2, 3, 4, 5, 6, 8, 12].map((w) => (
              <option key={w} value={w}>{w === 1 ? "semana" : `${w} semanas`}</option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nombre del cliente" className="field" />
        <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Teléfono" className="field" />
      </div>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="btn-ghost">Cancelar</button>
        <button onClick={submit} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Creando…" : "Crear recurrencia"}
        </button>
      </div>
    </div>
  );
}
