"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { admin, setToken } from "@/lib/admin";
import { isValidSlug, slugify } from "@/lib/slug";

export default function AltaPage() {
  const router = useRouter();
  const [businessName, setBusinessName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugTouched, setSlugTouched] = useState(false);
  const [locName, setLocName] = useState("");
  const [adminName, setAdminName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function changeBusinessName(v: string) {
    setBusinessName(v);
    if (!slugTouched) setSlug(slugify(v));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!isValidSlug(slug)) {
      setError("El identificador debe tener 3-40 caracteres: minúsculas, números o guiones.");
      return;
    }
    if (password.length < 8) {
      setError("La contraseña debe tener al menos 8 caracteres.");
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await admin.signup({
        business_name: businessName.trim(),
        slug,
        admin: { name: adminName.trim(), email: email.trim(), password },
        location: { name: locName.trim() || businessName.trim() },
      });
      setToken(res.token);
      router.replace("/panel");
    } catch (err) {
      setError(err instanceof Error ? err.message : "No se pudo crear la cuenta.");
      setLoading(false);
    }
  }

  return (
    <div className="grid min-h-screen place-items-center px-4 py-8">
      <div className="fade-up w-full max-w-md">
        <div className="mb-6 text-center">
          <span
            className="mx-auto grid h-12 w-12 place-items-center rounded-2xl text-2xl text-brand-ink"
            style={{ background: "var(--brand)", boxShadow: "0 10px 24px -10px var(--brand)" }}
          >
            ✂️
          </span>
          <h1 className="font-display mt-3 text-3xl font-bold tracking-tight">Crea tu cuenta</h1>
          <p className="mt-1 text-sm text-muted">
            Tu salón con reservas online y WhatsApp en un minuto. Sin tarjeta.
          </p>
        </div>

        <form onSubmit={submit} className="card space-y-4 p-6">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted">Tu negocio</p>
          <label className="block text-sm font-semibold">
            Nombre del negocio
            <input
              value={businessName}
              required
              onChange={(e) => changeBusinessName(e.target.value)}
              placeholder="Peluquería Lola"
              className="field"
            />
          </label>
          <label className="block text-sm font-semibold">
            Identificador
            <input
              value={slug}
              required
              onChange={(e) => { setSlugTouched(true); setSlug(e.target.value); }}
              placeholder="peluqueria-lola"
              className="field"
            />
            <span className="mt-1 block text-xs font-normal text-muted">
              Será tu dirección de reservas. Minúsculas, números y guiones.
            </span>
          </label>
          <label className="block text-sm font-semibold">
            Nombre de tu sede
            <input
              value={locName}
              onChange={(e) => setLocName(e.target.value)}
              placeholder="Salón centro (opcional, por defecto el negocio)"
              className="field"
            />
          </label>

          <p className="pt-1 text-xs font-semibold uppercase tracking-wide text-muted">Tu acceso</p>
          <label className="block text-sm font-semibold">
            Tu nombre
            <input value={adminName} required onChange={(e) => setAdminName(e.target.value)} className="field" />
          </label>
          <label className="block text-sm font-semibold">
            Email
            <input
              type="email"
              value={email}
              required
              autoComplete="email"
              onChange={(e) => setEmail(e.target.value)}
              className="field"
            />
          </label>
          <label className="block text-sm font-semibold">
            Contraseña
            <input
              type="password"
              value={password}
              required
              minLength={8}
              autoComplete="new-password"
              onChange={(e) => setPassword(e.target.value)}
              className="field"
            />
            <span className="mt-1 block text-xs font-normal text-muted">Mínimo 8 caracteres.</span>
          </label>

          {error ? <p className="text-sm text-red-700">{error}</p> : null}

          <button type="submit" disabled={loading} className="btn-primary w-full">
            {loading ? "Creando tu cuenta…" : "Crear mi cuenta gratis"}
          </button>

          <p className="text-center text-sm text-muted">
            ¿Ya tienes cuenta?{" "}
            <Link href="/panel/login" className="font-medium text-foreground underline-offset-2 hover:underline">
              Entra al panel
            </Link>
          </p>
        </form>
      </div>
    </div>
  );
}
