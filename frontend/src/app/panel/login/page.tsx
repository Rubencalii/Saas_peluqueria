"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { admin, setToken } from "@/lib/admin";

export default function PanelLogin() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await admin.login(email.trim(), password);
      setToken(res.token);
      router.replace("/panel");
    } catch {
      setError("Email o contraseña incorrectos.");
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
            ✂️
          </span>
          <h1 className="mt-3 text-2xl font-bold tracking-tight">Panel del salón</h1>
          <p className="mt-1 text-sm text-muted">Accede con tu cuenta del equipo.</p>
        </div>

        <form onSubmit={submit} className="card space-y-4 p-6">
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
              autoComplete="current-password"
              onChange={(e) => setPassword(e.target.value)}
              className="field"
            />
          </label>

          {error ? <p className="text-sm text-red-700">{error}</p> : null}

          <button type="submit" disabled={loading} className="btn-primary w-full">
            {loading ? "Entrando…" : "Entrar"}
          </button>

          <p className="text-center text-sm">
            <Link href="/recuperar-contrasena" className="text-muted underline-offset-2 hover:text-foreground hover:underline">
              ¿Has olvidado tu contraseña?
            </Link>
          </p>
        </form>

        <p className="mt-4 text-center text-sm text-muted">
          ¿Aún no tienes cuenta?{" "}
          <Link href="/alta" className="font-medium text-foreground underline-offset-2 hover:underline">
            Crea tu salón gratis
          </Link>
        </p>
      </div>
    </div>
  );
}
