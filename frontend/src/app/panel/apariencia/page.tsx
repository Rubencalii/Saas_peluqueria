"use client";

import { useEffect, useState } from "react";
import { admin, AdminApiError } from "@/lib/admin";
import { brandVars, contrastRatio, type Branding } from "@/lib/theme";

const DEFAULT_BRAND = "#a96f43";
const DEFAULT_ACCENT = "#2f7d6b";

/** Paletas seleccionadas a mano: marca + acento que combinan bien. */
const PALETTES: Array<{ name: string; brand: string; accent: string }> = [
  { name: "Terracota", brand: "#a96f43", accent: "#2f7d6b" },
  { name: "Grafito y oro", brand: "#2f2b28", accent: "#b08d57" },
  { name: "Bosque", brand: "#3e6b52", accent: "#b0713f" },
  { name: "Océano", brand: "#2e6b8a", accent: "#c76f4e" },
  { name: "Lavanda", brand: "#7b6b9e", accent: "#3e8e7e" },
  { name: "Rosa empolvado", brand: "#c08497", accent: "#5b7553" },
  { name: "Borgoña", brand: "#8a3b4a", accent: "#c9a227" },
  { name: "Azul noche", brand: "#34456b", accent: "#c98a4b" },
];

const HEX_RE = /^#[0-9a-fA-F]{6}$/;

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

  // Prueba en vivo: aplica la marca elegida a TODO el panel mientras editas y
  // restaura los valores guardados al salir de la página (o al restablecer).
  useEffect(() => {
    const root = document.documentElement;
    const vars = brandVars(preview) as Record<string, string>;
    const before: Record<string, string> = {};
    for (const k of Object.keys(vars)) before[k] = root.style.getPropertyValue(k);
    for (const [k, v] of Object.entries(vars)) root.style.setProperty(k, v);
    return () => {
      for (const [k, v] of Object.entries(before)) {
        if (v) root.style.setProperty(k, v);
        else root.style.removeProperty(k);
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brand, accent]);

  // Avisos de contraste (WCAG) sobre la elección actual.
  const warnings: string[] = [];
  if (brand) {
    const vsLight = contrastRatio(brand, "#fbf8f4");
    if (vsLight !== null && vsLight < 1.8) {
      warnings.push("El color de marca es muy claro: botones y acentos perderán presencia sobre fondo claro.");
    }
    const vsDark = contrastRatio(brand, "#17120e");
    if (vsDark !== null && vsDark < 1.8) {
      warnings.push("El color de marca es muy oscuro: en modo oscuro se confundirá con el fondo.");
    }
  }
  if (brand && accent) {
    const between = contrastRatio(brand, accent);
    if (between !== null && between < 1.3) {
      warnings.push("Marca y acento son casi iguales: el acento dejará de destacar (confirmaciones, éxito).");
    }
  }

  function applyPalette(p: { brand: string; accent: string }) {
    setBrand(p.brand);
    setAccent(p.accent);
    setMsg(null);
  }

  function onLogoFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = ""; // permite volver a elegir el mismo archivo
    if (!file) return;
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) {
      setMsg({ ok: false, text: "Sube una imagen PNG, JPG o WEBP." });
      return;
    }
    if (file.size > 200 * 1024) {
      setMsg({ ok: false, text: "La imagen es muy grande (máximo 200 KB)." });
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      setLogo(String(reader.result));
      setMsg(null);
    };
    reader.readAsDataURL(file);
  }

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

  if (loading) return <div className="skeleton h-64" />;

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Apariencia</h1>
        <p className="mt-1 text-sm text-muted">
          Personaliza tu web de reservas y este panel. Mientras editas, lo ves aplicado en vivo;
          nada se guarda hasta que pulses «Guardar».
        </p>
      </header>

      <div className="grid gap-5 lg:grid-cols-[1fr_1.15fr]">
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

          <div className="text-sm font-semibold">
            Paletas
            <div className="mt-2 grid grid-cols-4 gap-2">
              {PALETTES.map((p) => {
                const active = brand === p.brand && accent === p.accent;
                return (
                  <button
                    key={p.name}
                    type="button"
                    title={p.name}
                    onClick={() => applyPalette(p)}
                    className={
                      "group rounded-xl border p-2 transition hover:-translate-y-0.5 " +
                      (active ? "border-[var(--brand)] bg-brand-soft" : "border-border bg-card hover:border-[var(--ring)]")
                    }
                  >
                    <span className="flex justify-center">
                      <span className="h-6 w-6 rounded-full border border-white/40" style={{ background: p.brand }} />
                      <span className="-ml-2 h-6 w-6 rounded-full border border-white/40" style={{ background: p.accent }} />
                    </span>
                    <span className="mt-1 block truncate text-center text-[10px] font-medium text-muted">
                      {p.name}
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          <ColorField label="Color de marca" value={brand} fallback={DEFAULT_BRAND} onChange={setBrand} />
          <ColorField label="Color de acento" value={accent} fallback={DEFAULT_ACCENT} onChange={setAccent} />

          <div className="text-sm font-semibold">
            Logo
            <div className="mt-1.5 flex items-center gap-3">
              <div className="grid h-14 w-14 place-items-center overflow-hidden rounded-xl border border-border bg-card">
                {logo ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={logo} alt="" className="h-full w-full object-cover" />
                ) : (
                  <span className="text-muted">—</span>
                )}
              </div>
              <label className="btn-ghost cursor-pointer">
                Subir imagen
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  onChange={onLogoFile}
                  className="hidden"
                />
              </label>
              {logo ? (
                <button type="button" onClick={() => setLogo("")} className="text-xs font-medium text-muted underline">
                  Quitar
                </button>
              ) : null}
            </div>
            <p className="mt-1 text-xs font-normal text-muted">PNG, JPG o WEBP · máximo 200 KB.</p>
          </div>

          {warnings.map((w) => (
            <p key={w} className="rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
              ⚠️ {w}
            </p>
          ))}

          {msg ? (
            <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
              {msg.text}
            </p>
          ) : null}

          <button onClick={save} disabled={saving} className="btn-primary w-full">
            {saving ? "Guardando…" : "Guardar cambios"}
          </button>
        </section>

        {/* Vista previa fiel: web pública + panel */}
        <section className="space-y-4 lg:sticky lg:top-5 lg:self-start" style={brandVars(preview)}>
          <div className="card overflow-hidden">
            <p className="border-b border-border px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted">
              Tu web de reservas
            </p>
            <div className="p-5">
              {/* Cabecera */}
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  {logo ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={logo} alt="" className="h-8 w-8 rounded-full object-cover" />
                  ) : (
                    <span className="grid h-8 w-8 place-items-center rounded-full text-brand-ink" style={{ background: "var(--brand)" }}>
                      ✂️
                    </span>
                  )}
                  <span className="font-semibold">{name || accountName || "Tu salón"}</span>
                </div>
                <span className="chip bg-brand-soft text-foreground">Mi cita</span>
              </div>

              {/* Hero */}
              <div className="mt-4 rounded-2xl bg-brand-soft/70 p-4">
                <p className="font-display text-xl font-bold leading-tight">
                  Tu próxima cita, sin llamadas ni esperas.
                </p>
                <p className="mt-1 text-sm text-muted">Reserva online en menos de un minuto.</p>
              </div>

              {/* Servicio + huecos */}
              <div className="mt-3 rounded-2xl border border-border p-3">
                <div className="flex items-center justify-between text-sm">
                  <span className="font-semibold">Corte y peinado · 30 min</span>
                  <span className="font-semibold">18 €</span>
                </div>
                <div className="mt-2 flex gap-2">
                  <span className="slot px-3">10:30</span>
                  <span className="slot border-[var(--brand)] bg-brand-soft px-3">12:00</span>
                  <span className="slot px-3">17:15</span>
                </div>
              </div>

              <div className="mt-3 flex items-center justify-between gap-3">
                <span
                  className="chip text-white"
                  style={{ background: "var(--accent)" }}
                >
                  ✓ Cita confirmada
                </span>
                <button className="btn-primary px-5 py-2.5 text-xs" type="button">
                  Confirmar cita
                </button>
              </div>
            </div>
          </div>

          <div className="card overflow-hidden">
            <p className="border-b border-border px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted">
              Tu panel
            </p>
            <div className="flex">
              <div className="w-36 shrink-0 space-y-1 border-r border-border p-3 text-sm">
                <p className="rounded-lg px-2 py-1.5 text-muted">🏡 Inicio</p>
                <p className="rounded-lg bg-brand-soft px-2 py-1.5 font-medium">📅 Agenda</p>
                <p className="rounded-lg px-2 py-1.5 text-muted">👥 Clientes</p>
              </div>
              <div className="flex-1 p-4">
                <div className="card p-3">
                  <p className="text-xl font-bold">12</p>
                  <p className="text-xs text-muted">Citas hoy</p>
                  <p className="mt-1 text-xs font-medium" style={{ color: "var(--accent)" }}>
                    ▲ +8.3 % vs anterior
                  </p>
                </div>
              </div>
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
  const [text, setText] = useState(value ?? "");

  // Sincroniza el campo de texto cuando el valor cambia desde fuera (paletas).
  useEffect(() => {
    setText(value ?? "");
  }, [value]);

  function onText(v: string) {
    setText(v);
    const normalized = v.trim().startsWith("#") ? v.trim() : "#" + v.trim();
    if (HEX_RE.test(normalized)) onChange(normalized.toLowerCase());
  }

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
        <input
          value={text}
          onChange={(e) => onText(e.target.value)}
          placeholder="por defecto"
          spellCheck={false}
          className="field mt-0 w-32 font-mono text-sm"
        />
        {value ? (
          <button type="button" onClick={() => { onChange(null); setText(""); }} className="ml-auto text-xs font-medium text-muted underline">
            Restablecer
          </button>
        ) : null}
      </div>
    </div>
  );
}
