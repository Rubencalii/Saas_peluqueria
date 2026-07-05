"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type AdminService, type Pack } from "@/lib/admin";
import { formatPrice } from "@/lib/format";

export default function BonosPage() {
  const [packs, setPacks] = useState<Pack[]>([]);
  const [services, setServices] = useState<AdminService[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setPacks((await admin.packs()).packs);
    } catch {
      setPacks([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
    admin.services().then((r) => setServices(r.services)).catch(() => {});
  }, [load]);

  async function toggle(p: Pack) {
    await admin.setPackActive(p.id, !p.active);
    await load();
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Bonos</h1>
        <button onClick={() => setCreating(true)} className="btn-primary px-4 py-2.5">
          + Nuevo bono
        </button>
      </header>
      <p className="text-sm text-muted">
        Packs de sesiones prepagadas (p. ej. «5 cortes por 60 €»). Se venden desde la ficha del
        cliente y se descuentan solos al completar sus citas.
      </p>

      {creating ? (
        <NewPack
          services={services.filter((s) => s.active)}
          onClose={() => setCreating(false)}
          onCreated={async () => {
            setCreating(false);
            await load();
          }}
        />
      ) : null}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="skeleton h-16" />
          ))}
        </div>
      ) : packs.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">
          Aún no hay bonos. Crea el primero para fidelizar a tus clientes. 🎟️
        </p>
      ) : (
        <ul className="space-y-2">
          {packs.map((p) => (
            <li key={p.id} className={"card flex flex-wrap items-center justify-between gap-3 p-4 " + (p.active ? "" : "opacity-60")}>
              <div className="min-w-0">
                <p className="font-medium">
                  🎟️ {p.name}
                  {!p.active ? <span className="ml-2 chip bg-zinc-200 text-xs text-zinc-600">inactivo</span> : null}
                </p>
                <p className="mt-0.5 text-sm text-muted">
                  {p.service_name} · {p.sessions} sesiones · {formatPrice(p.price)}
                  {p.validity_days ? ` · caduca a los ${p.validity_days} días` : " · sin caducidad"}
                  {p.sold > 0 ? ` · ${p.sold} vendidos` : ""}
                </p>
              </div>
              <button onClick={() => toggle(p)} className="btn-ghost px-3 py-1.5 text-xs">
                {p.active ? "Desactivar" : "Reactivar"}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function NewPack({
  services,
  onClose,
  onCreated,
}: {
  services: AdminService[];
  onClose: () => void;
  onCreated: () => void;
}) {
  const [serviceId, setServiceId] = useState<number | "">("");
  const [name, setName] = useState("");
  const [sessions, setSessions] = useState(5);
  const [price, setPrice] = useState("");
  const [validity, setValidity] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (serviceId === "" || name.trim() === "" || price.trim() === "") {
      setError("Completa servicio, nombre y precio.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await admin.createPack({
        service_id: Number(serviceId),
        name: name.trim(),
        sessions,
        price: Number(price),
        validity_days: validity.trim() !== "" ? Number(validity) : null,
      });
      onCreated();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo crear el bono.");
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Nuevo bono</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm font-semibold">
          Servicio
          <select
            value={serviceId}
            onChange={(e) => {
              const v = e.target.value === "" ? "" : Number(e.target.value);
              setServiceId(v);
              const svc = services.find((s) => s.id === v);
              if (svc && name.trim() === "") setName(`Bono ${sessions} × ${svc.name}`);
            }}
            className="field"
          >
            <option value="">Elige un servicio…</option>
            {services.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Nombre del bono
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Bono 5 cortes" className="field" />
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <label className="block text-sm font-semibold">
          Sesiones
          <input
            type="number"
            min={1}
            max={1000}
            value={sessions}
            onChange={(e) => setSessions(Math.max(1, Number(e.target.value)))}
            className="field"
          />
        </label>
        <label className="block text-sm font-semibold">
          Precio (€)
          <input type="number" min={0} step="0.01" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="60" className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Validez (días)
          <input
            type="number"
            min={1}
            value={validity}
            onChange={(e) => setValidity(e.target.value)}
            placeholder="Sin caducidad"
            className="field"
          />
        </label>
      </div>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="btn-ghost">Cancelar</button>
        <button onClick={submit} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Creando…" : "Crear bono"}
        </button>
      </div>
    </div>
  );
}
