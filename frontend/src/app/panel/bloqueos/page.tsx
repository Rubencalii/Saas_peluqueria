"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type AdminLocation, type AdminStaff, type TimeBlock } from "@/lib/admin";

function isoDay(offsetDays: number): string {
  const n = new Date();
  n.setDate(n.getDate() + offsetDays);
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
}

function dtLocalDefault(hour: number): string {
  const n = new Date();
  n.setHours(hour, 0, 0, 0);
  const p = (x: number) => String(x).padStart(2, "0");
  return `${n.getFullYear()}-${p(n.getMonth() + 1)}-${p(n.getDate())}T${p(hour)}:00`;
}

export default function BloqueosPage() {
  const [staff, setStaff] = useState<AdminStaff[]>([]);
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [blocks, setBlocks] = useState<TimeBlock[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  const load = useCallback(async () => {
    const [b, st, l] = await Promise.all([
      admin.timeBlocks(isoDay(0), isoDay(60)),
      admin.staff(),
      admin.locations(),
    ]);
    setBlocks(b.time_blocks);
    setStaff(st.staff);
    setLocations(l.locations);
    setLoading(false);
  }, []);

  useEffect(() => {
    load().catch(() => setLoading(false));
  }, [load]);

  function tzOf(locId: number | null): string {
    return (locId && locations.find((l) => l.id === locId)?.timezone) || "Europe/Madrid";
  }
  function locName(locId: number | null): string {
    return locId ? (locations.find((l) => l.id === locId)?.name ?? "Sede") : "Todas las sedes";
  }
  function when(b: TimeBlock): string {
    const tz = tzOf(b.location_id);
    const d = (iso: string, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat("es-ES", { ...opts, timeZone: tz }).format(new Date(iso));
    const sameDay = d(b.start, { year: "numeric", month: "2-digit", day: "2-digit" }) === d(b.end, { year: "numeric", month: "2-digit", day: "2-digit" });
    if (sameDay) {
      return `${d(b.start, { weekday: "short", day: "numeric", month: "short" })} · ${d(b.start, { hour: "2-digit", minute: "2-digit" })}–${d(b.end, { hour: "2-digit", minute: "2-digit" })}`;
    }
    return `${d(b.start, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })} → ${d(b.end, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}`;
  }

  async function remove(id: number) {
    if (!confirm("¿Quitar este bloqueo? El horario volverá a estar disponible.")) return;
    await admin.deleteTimeBlock(id);
    await load();
  }

  if (loading) return <div className="card h-64 animate-pulse" />;

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Bloqueos de agenda</h1>
        <button onClick={() => setShowForm((v) => !v)} className="btn-primary px-4 py-2.5">
          {showForm ? "Cerrar" : "+ Nuevo bloqueo"}
        </button>
      </header>
      <p className="text-sm text-muted">
        Vacaciones, ausencias o descansos: el tiempo bloqueado no se ofrece para reservar.
      </p>

      {showForm ? (
        <BlockForm
          staff={staff}
          locations={locations}
          onClose={() => setShowForm(false)}
          onSaved={async () => {
            setShowForm(false);
            await load();
          }}
        />
      ) : null}

      {blocks.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">No hay bloqueos próximos.</p>
      ) : (
        <ul className="space-y-2">
          {blocks.map((b) => (
            <li key={b.id} className="card flex flex-wrap items-center justify-between gap-3 p-4">
              <div>
                <p className="font-medium">{b.staff.name}</p>
                <p className="mt-0.5 text-sm text-muted">
                  {when(b)} · {locName(b.location_id)}
                  {b.reason ? ` · ${b.reason}` : ""}
                </p>
              </div>
              <button onClick={() => remove(b.id)} className="btn-ghost px-3 py-1.5 text-xs text-red-700 hover:border-red-300">
                Quitar
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function BlockForm({
  staff,
  locations,
  onClose,
  onSaved,
}: {
  staff: AdminStaff[];
  locations: AdminLocation[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [staffId, setStaffId] = useState<number | "">("");
  const [locationId, setLocationId] = useState<number | "">("");
  const [start, setStart] = useState(dtLocalDefault(9));
  const [end, setEnd] = useState(dtLocalDefault(14));
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    if (staffId === "" || start === "" || end === "") {
      setError("Indica profesional, inicio y fin.");
      return;
    }
    const startIso = new Date(start).toISOString();
    const endIso = new Date(end).toISOString();
    if (endIso <= startIso) {
      setError("El fin debe ser posterior al inicio.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await admin.createTimeBlock({
        staff_id: Number(staffId),
        location_id: locationId === "" ? null : Number(locationId),
        start: startIso,
        end: endIso,
        reason: reason.trim() || null,
      });
      onSaved();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo crear el bloqueo.");
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm font-semibold">
          Profesional
          <select value={staffId} onChange={(e) => setStaffId(e.target.value === "" ? "" : Number(e.target.value))} className="field">
            <option value="">Elige…</option>
            {staff.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Sede
          <select value={locationId} onChange={(e) => setLocationId(e.target.value === "" ? "" : Number(e.target.value))} className="field">
            <option value="">Todas las sedes</option>
            {locations.map((l) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Inicio
          <input type="datetime-local" value={start} onChange={(e) => setStart(e.target.value)} className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Fin
          <input type="datetime-local" value={end} onChange={(e) => setEnd(e.target.value)} className="field" />
        </label>
      </div>
      <label className="block text-sm font-semibold">
        Motivo
        <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="opcional (vacaciones, médico…)" className="field" />
      </label>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="btn-ghost">Cancelar</button>
        <button onClick={save} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Guardando…" : "Crear bloqueo"}
        </button>
      </div>
    </div>
  );
}
