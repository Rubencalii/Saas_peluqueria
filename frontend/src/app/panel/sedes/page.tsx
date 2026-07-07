"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, AdminApiError, type AdminLocation, type LocationInput } from "@/lib/admin";

const TZ = ["Europe/Madrid", "Atlantic/Canary"];

export default function SedesPage() {
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<AdminLocation | "new" | null>(null);

  const load = useCallback(async () => {
    const r = await admin.locations();
    setLocations(r.locations);
    setLoading(false);
  }, []);

  useEffect(() => {
    load().catch(() => setLoading(false));
  }, [load]);

  if (loading) return <div className="card h-64 animate-pulse" />;

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Sedes</h1>
        <button onClick={() => setEditing("new")} className="btn-primary px-4 py-2.5">
          + Nueva sede
        </button>
      </header>

      {editing ? (
        <LocationEditor
          location={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await load();
          }}
        />
      ) : null}

      {locations.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">Aún no hay sedes. Crea la primera.</p>
      ) : (
        <ul className="space-y-2">
          {locations.map((l) => (
            <li key={l.id}>
              <button onClick={() => setEditing(l)} className="card w-full p-4 text-left transition hover:border-[var(--ring)]">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="font-medium">
                      {l.name}
                      {!l.active ? <span className="ml-2 chip bg-zinc-200 text-zinc-600">inactiva</span> : null}
                    </p>
                    <p className="mt-0.5 text-sm text-muted">
                      /{l.slug}
                      {l.address ? ` · ${l.address}` : ""}
                      {l.phone ? ` · ${l.phone}` : ""}
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

function LocationEditor({
  location,
  onClose,
  onSaved,
}: {
  location: AdminLocation | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(location?.name ?? "");
  const [slug, setSlug] = useState(location?.slug ?? "");
  const [address, setAddress] = useState(location?.address ?? "");
  const [phone, setPhone] = useState(location?.phone ?? "");
  const [timezone, setTimezone] = useState(location?.timezone ?? "Europe/Madrid");
  const [active, setActive] = useState(location?.active ?? true);
  const [googleUrl, setGoogleUrl] = useState(location?.google_review_url ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function slugify(v: string) {
    return v
      .toLowerCase()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  async function save() {
    if (name.trim() === "" || slug.trim() === "") {
      setError("Indica nombre y un identificador (slug).");
      return;
    }
    setSaving(true);
    setError(null);
    const body: LocationInput = {
      name: name.trim(),
      slug: slug.trim(),
      address: address.trim() || null,
      phone: phone.trim() || null,
      timezone,
      active,
      google_review_url: googleUrl.trim() || null,
    };
    try {
      if (location) await admin.updateLocation(location.id, body);
      else await admin.createLocation(body);
      onSaved();
    } catch (e) {
      if (e instanceof AdminApiError && e.code === "PLAN_LIMIT") {
        setError("Tu plan no permite más sedes. Mejóralo en «Cuenta» para añadir otra.");
      } else if (e instanceof AdminApiError && e.code === "CONFLICT") {
        setError("Ya existe una sede con ese identificador (slug).");
      } else {
        setError(e instanceof Error ? e.message : "No se pudo guardar.");
      }
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">{location ? "Editar sede" : "Nueva sede"}</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm font-semibold">
          Nombre
          <input
            value={name}
            onChange={(e) => {
              setName(e.target.value);
              if (!location && (slug === "" || slug === slugify(name))) setSlug(slugify(e.target.value));
            }}
            className="field"
          />
        </label>
        <label className="block text-sm font-semibold">
          Identificador (slug)
          <input value={slug} onChange={(e) => setSlug(slugify(e.target.value))} className="field font-mono" />
        </label>
        <label className="block text-sm font-semibold">
          Dirección
          <input value={address} onChange={(e) => setAddress(e.target.value)} placeholder="opcional" className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Teléfono
          <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="opcional" className="field" />
        </label>
        <label className="block text-sm font-semibold">
          Zona horaria
          <select value={timezone} onChange={(e) => setTimezone(e.target.value)} className="field">
            {TZ.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold">
          Enlace de reseñas de Google
          <input
            value={googleUrl}
            onChange={(e) => setGoogleUrl(e.target.value)}
            placeholder="https://g.page/r/…/review (opcional)"
            className="field"
          />
          <span className="mt-1 block text-xs font-normal text-muted">
            Tras una valoración de 4-5★ invitamos al cliente a dejarla también en Google.
          </span>
        </label>
      </div>

      <label className="flex items-center gap-2 text-sm font-medium">
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="h-4 w-4 accent-[var(--brand)]" />
        Sede activa (reservable)
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
