"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type AdminLocation, type AdminService, type ServiceInput } from "@/lib/admin";
import { formatPrice } from "@/lib/format";

export default function ServiciosPage() {
  const [services, setServices] = useState<AdminService[]>([]);
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<AdminService | "new" | null>(null);

  const load = useCallback(async () => {
    const [s, l] = await Promise.all([admin.services(), admin.locations()]);
    setServices(s.services);
    setLocations(l.locations);
    setLoading(false);
  }, []);

  useEffect(() => {
    load().catch(() => setLoading(false));
  }, [load]);

  function locName(id: number) {
    return locations.find((l) => l.id === id)?.name ?? "";
  }

  if (loading) return <div className="card h-64 animate-pulse" />;

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Servicios</h1>
        <button onClick={() => setEditing("new")} className="btn-primary px-4 py-2.5">
          + Nuevo servicio
        </button>
      </header>

      {editing ? (
        <ServiceEditor
          service={editing === "new" ? null : editing}
          locations={locations}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await load();
          }}
        />
      ) : null}

      {services.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">Aún no hay servicios. Crea el primero.</p>
      ) : (
        <ul className="space-y-2">
          {services.map((s) => (
            <li key={s.id}>
              <button onClick={() => setEditing(s)} className="card w-full p-4 text-left transition hover:border-[var(--ring)]">
                <div className="flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-medium">
                      {s.name}
                      {!s.active ? <span className="ml-2 chip bg-zinc-200 text-zinc-600">inactivo</span> : null}
                    </p>
                    <p className="mt-0.5 text-sm text-muted">
                      {s.duration_min} min{s.price !== null ? ` · ${formatPrice(s.price)}` : ""}
                      {s.locations.length > 0
                        ? ` · ${s.locations.map((o) => locName(o.location_id)).filter(Boolean).join(", ")}`
                        : " · sin sedes (no reservable)"}
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

function ServiceEditor({
  service,
  locations,
  onClose,
  onSaved,
}: {
  service: AdminService | null;
  locations: AdminLocation[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(service?.name ?? "");
  const [duration, setDuration] = useState(String(service?.duration_min ?? 30));
  const [buffer, setBuffer] = useState(String(service?.buffer_min ?? 0));
  const [price, setPrice] = useState(service?.price != null ? String(service.price) : "");
  const [deposit, setDeposit] = useState(service?.deposit_amount != null ? String(service.deposit_amount) : "");
  const [description, setDescription] = useState(service?.description ?? "");
  const [active, setActive] = useState(service?.active ?? true);

  const [offers, setOffers] = useState<Record<number, { on: boolean; override: string }>>(() => {
    const map: Record<number, { on: boolean; override: string }> = {};
    for (const l of locations) {
      const found = service?.locations.find((o) => o.location_id === l.id);
      map[l.id] = { on: !!found, override: found?.price_override != null ? String(found.price_override) : "" };
    }
    return map;
  });

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function num(v: string): number | null {
    const t = v.trim().replace(",", ".");
    if (t === "") return null;
    const n = Number(t);
    return Number.isFinite(n) ? n : null;
  }

  async function save() {
    if (name.trim() === "" || (num(duration) ?? 0) <= 0) {
      setError("Indica nombre y una duración mayor que 0.");
      return;
    }
    setSaving(true);
    setError(null);
    const body: ServiceInput = {
      name: name.trim(),
      duration_min: num(duration) ?? 0,
      buffer_min: num(buffer) ?? 0,
      price: num(price),
      deposit_amount: num(deposit),
      description: description.trim() || null,
      active,
      locations: Object.entries(offers)
        .filter(([, v]) => v.on)
        .map(([id, v]) => ({ location_id: Number(id), price_override: num(v.override) })),
    };
    try {
      if (service) await admin.updateService(service.id, body);
      else await admin.createService(body);
      onSaved();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo guardar.");
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">{service ? "Editar servicio" : "Nuevo servicio"}</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <label className="block text-sm font-semibold">
        Nombre
        <input value={name} onChange={(e) => setName(e.target.value)} className="field" />
      </label>

      <div className="grid grid-cols-2 gap-3">
        <label className="block text-sm font-semibold">
          Duración (min)
          <input type="number" min={1} value={duration} onChange={(e) => setDuration(e.target.value)} className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Margen extra (min)
          <input type="number" min={0} value={buffer} onChange={(e) => setBuffer(e.target.value)} className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Precio (€)
          <input value={price} onChange={(e) => setPrice(e.target.value)} placeholder="opcional" className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Depósito (€)
          <input value={deposit} onChange={(e) => setDeposit(e.target.value)} placeholder="opcional" className="field" />
        </label>
      </div>

      <label className="block text-sm font-semibold">
        Descripción
        <input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="opcional" className="field" />
      </label>

      <div className="text-sm font-semibold">
        Se ofrece en
        <div className="mt-1.5 space-y-2">
          {locations.map((l) => {
            const o = offers[l.id] ?? { on: false, override: "" };
            return (
              <div key={l.id} className="flex items-center gap-3 rounded-xl bg-brand-soft/40 px-3 py-2">
                <label className="flex flex-1 items-center gap-2 font-normal">
                  <input
                    type="checkbox"
                    checked={o.on}
                    onChange={(e) => setOffers({ ...offers, [l.id]: { ...o, on: e.target.checked } })}
                    className="h-4 w-4 accent-[var(--brand)]"
                  />
                  {l.name}
                </label>
                {o.on ? (
                  <input
                    value={o.override}
                    onChange={(e) => setOffers({ ...offers, [l.id]: { ...o, override: e.target.value } })}
                    placeholder="precio aquí (opcional)"
                    className="w-40 rounded-lg border border-border bg-card px-2 py-1 text-sm font-normal"
                  />
                ) : null}
              </div>
            );
          })}
          {locations.length === 0 ? <p className="font-normal text-muted">No hay sedes todavía.</p> : null}
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm font-medium">
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="h-4 w-4 accent-[var(--brand)]" />
        Servicio activo (reservable)
      </label>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="btn-ghost">Cancelar</button>
        <button onClick={save} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Guardando…" : "Guardar"}
        </button>
      </div>
    </div>
  );
}
