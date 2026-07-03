"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { admin } from "@/lib/admin";

export default function RestablecerContrasenaPage() {
  const [token, setTokenValue] = useState<string | null>(null);
  const [password, setPassword] = useState("");
  const [repeat, setRepeat] = useState("");
  const [done, setDone] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setTokenValue(new URLSearchParams(window.location.search).get("token") ?? "");
  }, []);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (password.length < 8) {
      setError("La contraseña debe tener al menos 8 caracteres.");
      return;
    }
    if (password !== repeat) {
      setError("Las contraseñas no coinciden.");
      return;
    }
    setLoading(true);
    setError(null);
    try {
      await admin.resetPassword(token ?? "", password);
      setDone(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "No se pudo restablecer la contraseña.");
      setLoading(false);
    }
  }

  return (
    <div className="grid min-h-screen place-items-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <span
            className="mx-auto grid h-12 w-12 place-items-center rounded-2xl text-2xl text-brand-ink"
            style={{ background: "var(--brand)" }}
          >
            🔒
          </span>
          <h1 className="mt-3 text-2xl font-bold tracking-tight">Nueva contraseña</h1>
        </div>

        {done ? (
          <div className="card p-6 text-center">
            <div
              className="mx-auto grid h-14 w-14 place-items-center rounded-full text-2xl text-white"
              style={{ background: "var(--accent)" }}
            >
              ✓
            </div>
            <p className="mt-3 font-medium">Contraseña actualizada</p>
            <p className="mt-1 text-sm text-muted">Ya puedes entrar con tu nueva contraseña.</p>
            <Link href="/panel/login" className="btn-primary mt-5 inline-flex">Ir al login</Link>
          </div>
        ) : token === null ? (
          <p className="card p-6 text-center text-sm text-muted">Cargando…</p>
        ) : token === "" ? (
          <div className="card p-6 text-center">
            <div className="text-4xl">⚠️</div>
            <p className="mt-2 font-medium">Falta el enlace</p>
            <p className="mt-1 text-sm text-muted">Abre el enlace completo del correo o pide uno nuevo.</p>
            <Link href="/recuperar-contrasena" className="btn-ghost mt-5 inline-flex">Pedir enlace nuevo</Link>
          </div>
        ) : (
          <form onSubmit={submit} className="card space-y-4 p-6">
            <label className="block text-sm font-semibold">
              Nueva contraseña
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
            <label className="block text-sm font-semibold">
              Repite la contraseña
              <input
                type="password"
                value={repeat}
                required
                autoComplete="new-password"
                onChange={(e) => setRepeat(e.target.value)}
                className="field"
              />
            </label>

            {error ? <p className="text-sm text-red-700">{error}</p> : null}

            <button type="submit" disabled={loading} className="btn-primary w-full">
              {loading ? "Guardando…" : "Guardar contraseña"}
            </button>
            <p className="text-center text-sm text-muted">
              ¿Enlace caducado?{" "}
              <Link href="/recuperar-contrasena" className="font-medium text-foreground underline-offset-2 hover:underline">
                Pide otro
              </Link>
            </p>
          </form>
        )}
      </div>
    </div>
  );
}
