"use client";

import { useCallback, useEffect, useState } from "react";
import {
  admin,
  type AdminLocation,
  type AdminService,
  type AdminStaff,
  type StaffInput,
} from "@/lib/admin";
import { formatPrice } from "@/lib/format";

const DAYS = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"];

export default function PersonalPage() {
  const [staff, setStaff] = useState<AdminStaff[]>([]);
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [services, setServices] = useState<AdminService[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<AdminStaff | "new" | null>(null);

  const load = useCallback(async () => {
    const [st, l, sv] = await Promise.all([admin.staff(), admin.locations(), admin.services()]);
    setStaff(st.staff);
    setLocations(l.locations);
    setServices(sv.services);
    setLoading(false);
  }, []);

  useEffect(() => {
    load().catch(() => setLoading(false));
  }, [load]);

  if (loading) return <div className="card h-64 animate-pulse" />;

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Personal</h1>
        <button onClick={() => setEditing("new")} className="btn-primary px-4 py-2.5">
          + Nuevo profesional
        </button>
      </header>

      {editing ? (
        <StaffEditor
          staff={editing === "new" ? null : editing}
          locations={locations}
          services={services}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await load();
          }}
        />
      ) : null}

      {staff.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">Aún no hay profesionales. Crea el primero.</p>
      ) : (
        <ul className="space-y-2">
          {staff.map((s) => (
            <li key={s.id}>
              <button onClick={() => setEditing(s)} className="card w-full p-4 text-left transition hover:border-[var(--ring)]">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="font-medium">
                      {s.name}
                      {!s.active ? <span className="ml-2 chip bg-zinc-200 text-zinc-600">inactivo</span> : null}
                    </p>
                    <p className="mt-0.5 text-sm text-muted">
                      {[s.phone, s.email].filter(Boolean).join(" · ") || "Sin contacto"} ·{" "}
                      {s.location_ids.length} sede(s) · {s.service_ids.length} servicio(s)
                    </p>
                  </div>
                  <span className="shrink-0 text-muted">✎</span>
                </div>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function StaffEditor({
  staff,
  locations,
  services,
  onClose,
  onSaved,
}: {
  staff: AdminStaff | null;
  locations: AdminLocation[];
  services: AdminService[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(staff?.name ?? "");
  const [email, setEmail] = useState(staff?.email ?? "");
  const [phone, setPhone] = useState(staff?.phone ?? "");
  const [active, setActive] = useState(staff?.active ?? true);
  const [locIds, setLocIds] = useState<number[]>(staff?.location_ids ?? []);
  const [svcIds, setSvcIds] = useState<number[]>(staff?.service_ids ?? []);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function toggle(list: number[], id: number): number[] {
    return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
  }

  async function save() {
    if (name.trim() === "") {
      setError("El nombre es obligatorio.");
      return;
    }
    setSaving(true);
    setError(null);
    const body: StaffInput = {
      name: name.trim(),
      email: email.trim() || null,
      phone: phone.trim() || null,
      active,
      location_ids: locIds,
      service_ids: svcIds,
    };
    try {
      if (staff) await admin.updateStaff(staff.id, body);
      else await admin.createStaff(body);
      onSaved();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo guardar.");
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      <div className="card space-y-4 p-5">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold">{staff ? "Editar profesional" : "Nuevo profesional"}</h2>
          <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
        </div>

        <label className="block text-sm font-semibold">
          Nombre
          <input value={name} onChange={(e) => setName(e.target.value)} className="field" />
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-sm font-semibold">
            Teléfono
            <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="opcional" className="field" />
          </label>
          <label className="block text-sm font-semibold">
            Email
            <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="opcional" className="field" />
          </label>
        </div>

        <CheckList
          label="Trabaja en"
          items={locations.map((l) => ({ id: l.id, name: l.name }))}
          selected={locIds}
          onToggle={(id) => setLocIds(toggle(locIds, id))}
          empty="No hay sedes todavía."
        />
        <CheckList
          label="Servicios que ofrece"
          items={services.map((s) => ({ id: s.id, name: s.name }))}
          selected={svcIds}
          onToggle={(id) => setSvcIds(toggle(svcIds, id))}
          empty="No hay servicios todavía."
        />

        <label className="flex items-center gap-2 text-sm font-medium">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="h-4 w-4 accent-[var(--brand)]" />
          Profesional activo
        </label>

        {error ? <p className="text-sm text-red-700">{error}</p> : null}

        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="btn-ghost">Cancelar</button>
          <button onClick={save} disabled={saving} className="btn-primary px-5 py-2.5">
            {saving ? "Guardando…" : "Guardar"}
          </button>
        </div>
      </div>

      {staff ? <ScheduleEditor staff={staff} locations={locations} /> : null}
      {staff ? <CommissionsEditor staff={staff} services={services} /> : null}
    </div>
  );
}

/**
 * Comisiones del profesional: una tarifa general y, opcionalmente, un
 * porcentaje distinto para servicios concretos. Vacío = sin comisión (0 %).
 */
function CommissionsEditor({ staff, services }: { staff: AdminStaff; services: AdminService[] }) {
  const [defaultRate, setDefaultRate] = useState("");
  const [rates, setRates] = useState<Record<number, string>>({});
  // Los servicios que ofrece y, además, cualquiera con tarifa guardada: si se
  // le quita un servicio, su excepción sigue visible en vez de borrarse sola.
  const own = services.filter((s) => staff.service_ids.includes(s.id) || rates[s.id] !== undefined);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    admin
      .staffCommissions(staff.id)
      .then((c) => {
        setDefaultRate(c.default_rate_pct === null ? "" : String(c.default_rate_pct));
        setRates(Object.fromEntries(c.by_service.map((s) => [s.service_id, String(s.rate_pct)])));
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [staff.id]);

  /** "" → null (sin tarifa); número fuera de 0..100 → undefined (inválido). */
  function parseRate(raw: string): number | null | undefined {
    const v = raw.trim().replace(",", ".");
    if (v === "") return null;
    const n = Number(v);
    return Number.isFinite(n) && n >= 0 && n <= 100 ? n : undefined;
  }

  async function save() {
    const general = parseRate(defaultRate);
    if (general === undefined) {
      setMsg({ ok: false, text: "La comisión general debe ser un porcentaje entre 0 y 100." });
      return;
    }
    const byService: Array<{ service_id: number; rate_pct: number }> = [];
    for (const s of own) {
      const n = parseRate(rates[s.id] ?? "");
      if (n === undefined) {
        setMsg({ ok: false, text: `La comisión de "${s.name}" debe ser un porcentaje entre 0 y 100.` });
        return;
      }
      if (n !== null) byService.push({ service_id: s.id, rate_pct: n });
    }

    setSaving(true);
    setMsg(null);
    try {
      await admin.setStaffCommissions(staff.id, general, byService);
      setMsg({ ok: true, text: "Comisiones guardadas." });
    } catch (e) {
      setMsg({ ok: false, text: e instanceof Error ? e.message : "No se pudieron guardar las comisiones." });
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <div className="card h-32 animate-pulse" />;

  return (
    <div className="card space-y-4 p-5">
      <div>
        <h2 className="text-lg font-semibold">Comisiones</h2>
        <p className="mt-0.5 text-sm text-muted">
          Porcentaje sobre las citas completadas. Lo ves liquidado en Informes → Comisiones.
        </p>
      </div>

      <label className="block text-sm font-semibold">
        Comisión general
        <div className="mt-1 flex items-center gap-2">
          <input
            value={defaultRate}
            onChange={(e) => setDefaultRate(e.target.value)}
            inputMode="decimal"
            placeholder="sin comisión"
            className="field w-32"
          />
          <span className="text-muted">%</span>
        </div>
      </label>

      {own.length === 0 ? (
        <p className="text-sm text-muted">
          Asigna servicios a este profesional (arriba) para afinar la comisión servicio a servicio.
        </p>
      ) : (
        <div className="text-sm font-semibold">
          Excepciones por servicio
          <p className="mb-2 mt-0.5 font-normal text-muted">En blanco se aplica la comisión general.</p>
          <div className="space-y-2">
            {own.map((s) => {
              const rate = parseRate(rates[s.id] ?? "") ?? parseRate(defaultRate) ?? 0;
              const perAppt = s.price !== null && rate > 0 ? (s.price * rate) / 100 : null;
              return (
                <div key={s.id} className="flex flex-wrap items-center gap-2">
                  <span className="min-w-40 flex-1 font-normal">{s.name}</span>
                  <input
                    value={rates[s.id] ?? ""}
                    onChange={(e) => setRates({ ...rates, [s.id]: e.target.value })}
                    inputMode="decimal"
                    placeholder="general"
                    aria-label={`Comisión de ${s.name}`}
                    className="w-24 rounded-lg border border-border bg-card px-2 py-1.5 text-sm"
                  />
                  <span className="text-muted">%</span>
                  <span className="w-28 text-right text-xs font-normal text-muted">
                    {perAppt !== null ? `≈ ${formatPrice(perAppt)}/cita` : ""}
                  </span>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {msg ? (
        <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
          {msg.text}
        </p>
      ) : null}

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Guardando…" : "Guardar comisiones"}
        </button>
      </div>
    </div>
  );
}

function CheckList({
  label,
  items,
  selected,
  onToggle,
  empty,
}: {
  label: string;
  items: Array<{ id: number; name: string }>;
  selected: number[];
  onToggle: (id: number) => void;
  empty: string;
}) {
  return (
    <div className="text-sm font-semibold">
      {label}
      {items.length === 0 ? (
        <p className="mt-1 font-normal text-muted">{empty}</p>
      ) : (
        <div className="mt-1.5 flex flex-wrap gap-2">
          {items.map((it) => {
            const on = selected.includes(it.id);
            return (
              <button
                key={it.id}
                type="button"
                onClick={() => onToggle(it.id)}
                className={
                  "rounded-full border px-3 py-1.5 text-sm font-medium transition " +
                  (on ? "border-[var(--brand)] bg-brand-soft" : "border-border bg-card text-muted hover:border-[var(--ring)]")
                }
              >
                {on ? "✓ " : ""}
                {it.name}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function ScheduleEditor({ staff, locations }: { staff: AdminStaff; locations: AdminLocation[] }) {
  const own = locations.filter((l) => staff.location_ids.includes(l.id));
  const [locationId, setLocationId] = useState<number | null>(own[0]?.id ?? null);
  const [rows, setRows] = useState<Array<{ weekday: number; start_time: string; end_time: string }>>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  const loadSchedule = useCallback(async (loc: number) => {
    setLoading(true);
    setMsg(null);
    try {
      const r = await admin.staffSchedule(staff.id);
      setRows(
        r.schedule
          .filter((e) => e.location_id === loc)
          .map((e) => ({ weekday: e.weekday, start_time: e.start_time, end_time: e.end_time })),
      );
    } catch {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [staff.id]);

  useEffect(() => {
    if (locationId) void loadSchedule(locationId);
  }, [locationId, loadSchedule]);

  async function save() {
    if (!locationId) return;
    for (const r of rows) {
      if (r.start_time >= r.end_time) {
        setMsg({ ok: false, text: "Cada tramo: la hora de inicio debe ser anterior a la de fin." });
        return;
      }
    }
    setSaving(true);
    setMsg(null);
    try {
      await admin.setStaffSchedule(staff.id, locationId, rows);
      setMsg({ ok: true, text: "Horario guardado." });
    } catch (e) {
      setMsg({ ok: false, text: e instanceof Error ? e.message : "No se pudo guardar el horario." });
    } finally {
      setSaving(false);
    }
  }

  if (own.length === 0) {
    return (
      <div className="card p-5 text-sm text-muted">
        Asigna al menos una sede (arriba) y guarda para poder definir el horario.
      </div>
    );
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">Horario semanal</h2>
        {own.length > 1 ? (
          <select
            value={locationId ?? ""}
            onChange={(e) => setLocationId(Number(e.target.value))}
            className="rounded-xl border border-border bg-card px-3 py-2 text-sm"
          >
            {own.map((l) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </select>
        ) : null}
      </div>

      {loading ? (
        <div className="h-24 animate-pulse rounded-2xl bg-brand-soft/50" />
      ) : (
        <>
          {rows.length === 0 ? (
            <p className="text-sm text-muted">Sin tramos. Añade el horario de esta sede.</p>
          ) : (
            <div className="space-y-2">
              {rows.map((r, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                  <select
                    value={r.weekday}
                    onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, weekday: Number(e.target.value) } : x)))}
                    className="rounded-lg border border-border bg-card px-2 py-1.5 text-sm"
                  >
                    {DAYS.map((d, idx) => (
                      <option key={idx} value={idx}>{d}</option>
                    ))}
                  </select>
                  <input
                    type="time"
                    value={r.start_time}
                    onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, start_time: e.target.value } : x)))}
                    className="rounded-lg border border-border bg-card px-2 py-1.5 text-sm"
                  />
                  <span className="text-muted">–</span>
                  <input
                    type="time"
                    value={r.end_time}
                    onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, end_time: e.target.value } : x)))}
                    className="rounded-lg border border-border bg-card px-2 py-1.5 text-sm"
                  />
                  <button
                    type="button"
                    onClick={() => setRows(rows.filter((_, j) => j !== i))}
                    className="text-muted hover:text-red-700"
                    aria-label="Quitar tramo"
                  >
                    ✕
                  </button>
                </div>
              ))}
            </div>
          )}

          <button
            type="button"
            onClick={() => setRows([...rows, { weekday: 0, start_time: "09:00", end_time: "14:00" }])}
            className="text-sm font-medium text-brand-strong underline"
          >
            + Añadir tramo
          </button>

          {msg ? (
            <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
              {msg.text}
            </p>
          ) : null}

          <div className="flex justify-end">
            <button onClick={save} disabled={saving} className="btn-primary px-5 py-2.5">
              {saving ? "Guardando…" : "Guardar horario"}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
