"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { admin, AdminApiError, setToken } from "@/lib/admin";

export default function PanelLogin() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [totp, setTotp] = useState("");
  const [totpNeeded, setTotpNeeded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await admin.login(email.trim(), password, totpNeeded ? totp.trim() : undefined);
      setToken(res.token);
      router.replace("/panel");
    } catch (err) {
      if (err instanceof AdminApiError && err.code === "TOTP_REQUIRED") {
        // Credenciales correctas: falta el segundo factor.
        setTotpNeeded(true);
      } else if (err instanceof AdminApiError && err.code === "TOTP_INVALID") {
        setError("Código de verificación incorrecto.");
      } else {
        setError("Email o contraseña incorrectos.");
      }
      setLoading(false);
    }
  }

  return (
    <div className="grid min-h-screen place-items-center px-4">
      <div className="fade-up w-full max-w-sm">
        <div className="mb-6 text-center">
          <span
            className="mx-auto grid h-12 w-12 place-items-center rounded-2xl text-2xl text-brand-ink"
            style={{ background: "var(--brand)", boxShadow: "0 10px 24px -10px var(--brand)" }}
          >
            ✂️
          </span>
          <h1 className="font-display mt-3 text-3xl font-bold tracking-tight">Panel del salón</h1>
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

          {totpNeeded ? (
            <label className="block text-sm font-semibold">
              Código de verificación
              <input
                value={totp}
                required
                autoFocus
                inputMode="numeric"
                autoComplete="one-time-code"
                placeholder="123456"
                maxLength={6}
                onChange={(e) => setTotp(e.target.value)}
                className="field text-center font-mono text-lg tracking-[0.4em]"
              />
              <span className="mt-1 block text-xs font-normal text-muted">
                El código de 6 dígitos de tu app de autenticación.
              </span>
            </label>
          ) : null}

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
