"use client";

import { useEffect, useState } from "react";
import { admin, AdminApiError } from "@/lib/admin";
import { brandVars, type Branding } from "@/lib/theme";

const DEFAULT_BRAND = "#a96f43";
const DEFAULT_ACCENT = "#2f7d6b";

export default function AparienciaPage() {
  const [name, setName] = useState("");
  const [accountName, setAccountName] = useState("");
  const [brand, setBrand] = useState<string | null>(null);
  const [accent, setAccent] = useState<string | null>(null);
  const [logo, setLogo] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    admin
      .branding()
      .then((r) => {
        const b = r.branding;
        setAccountName(b.name);
        setName(b.display_name ?? "");
        setBrand(b.brand_color);
        setAccent(b.accent_color);
        setLogo(b.logo_url ?? "");
      })
      .catch(() => setMsg({ ok: false, text: "No se pudo cargar la apariencia." }))
      .finally(() => setLoading(false));
  }, []);

  const preview: Branding = {
    name: accountName,
    display_name: name || null,
    brand_color: brand,
    accent_color: accent,
    logo_url: logo || null,
  };

  async function save() {
    setSaving(true);
    setMsg(null);
    try {
      await admin.updateBranding({
        display_name: name.trim() || null,
        brand_color: brand,
        accent_color: accent,
        logo_url: logo.trim() || null,
      });
      setMsg({ ok: true, text: "Guardado. Aplicando el nuevo aspecto…" });
      setTimeout(() => window.location.reload(), 700);
    } catch (e) {
      const text = e instanceof AdminApiError ? e.message : "No se pudo guardar.";
      setMsg({ ok: false, text });
      setSaving(false);
    }
  }

  if (loading) return <div className="card h-64 animate-pulse" />;

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Apariencia</h1>
        <p className="mt-1 text-sm text-muted">
          Personaliza el aspecto de tu web de reserva y de este panel. Los cambios se aplican a toda
          tu cuenta.
        </p>
      </header>

      <div className="grid gap-5 md:grid-cols-2">
        {/* Formulario */}
        <section className="card space-y-5 p-5">
          <label className="block text-sm font-semibold">
            Nombre visible
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={accountName}
              className="field"
            />
          </label>

          <ColorField label="Color de marca" value={brand} fallback={DEFAULT_BRAND} onChange={setBrand} />
          <ColorField label="Color de acento" value={accent} fallback={DEFAULT_ACCENT} onChange={setAccent} />

          <label className="block text-sm font-semibold">
            Logo (URL)
            <input
              value={logo}
              onChange={(e) => setLogo(e.target.value)}
              placeholder="https://…/logo.png"
              className="field"
            />
          </label>

          {msg ? (
            <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
              {msg.text}
            </p>
          ) : null}

          <button onClick={save} disabled={saving} className="btn-primary w-full">
            {saving ? "Guardando…" : "Guardar cambios"}
          </button>
        </section>

        {/* Vista previa */}
        <section className="card overflow-hidden p-5" style={brandVars(preview)}>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">Vista previa</p>
          <div className="rounded-2xl border border-border p-4">
            <div className="flex items-center gap-2">
              {logo ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={logo} alt="" className="h-9 w-9 rounded-full object-cover" />
              ) : (
                <span className="grid h-9 w-9 place-items-center rounded-full text-brand-ink" style={{ background: "var(--brand)" }}>
                  ✂️
                </span>
              )}
              <span className="font-semibold">{name || accountName || "Tu salón"}</span>
            </div>

            <div className="mt-4 rounded-xl bg-brand-soft p-3 text-sm">
              <p className="font-medium">Reserva tu cita</p>
              <p className="text-muted">Elige servicio, día y hora.</p>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2">
              <span className="chip bg-brand-soft text-foreground">Servicio</span>
              <span className="slot px-3">10:30</span>
              <button className="btn-primary px-4 py-2 text-xs" type="button">
                Confirmar
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}

function ColorField({
  label,
  value,
  fallback,
  onChange,
}: {
  label: string;
  value: string | null;
  fallback: string;
  onChange: (v: string | null) => void;
}) {
  return (
    <div className="text-sm font-semibold">
      {label}
      <div className="mt-1.5 flex items-center gap-3">
        <input
          type="color"
          value={value ?? fallback}
          onChange={(e) => onChange(e.target.value)}
          className="h-10 w-14 cursor-pointer rounded-lg border border-border bg-card"
        />
        <span className="font-mono text-sm font-normal text-muted">{value ?? "por defecto"}</span>
        {value ? (
          <button type="button" onClick={() => onChange(null)} className="ml-auto text-xs font-medium text-muted underline">
            Restablecer
          </button>
        ) : null}
      </div>
    </div>
  );
}
