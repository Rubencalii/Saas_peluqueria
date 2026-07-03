"use client";

import { useState } from "react";
import Link from "next/link";
import { admin } from "@/lib/admin";

export default function RecuperarContrasenaPage() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      await admin.forgotPassword(email.trim());
    } catch {
      // Respuesta genérica igualmente: no revelamos si el email existe.
    }
    setSent(true);
    setLoading(false);
  }

  return (
    <div className="grid min-h-screen place-items-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <span
            className="mx-auto grid h-12 w-12 place-items-center rounded-2xl text-2xl text-brand-ink"
            style={{ background: "var(--brand)" }}
          >
            🔑
          </span>
          <h1 className="mt-3 text-2xl font-bold tracking-tight">Recuperar contraseña</h1>
          <p className="mt-1 text-sm text-muted">Te enviaremos un enlace para restablecerla.</p>
        </div>

        {sent ? (
          <div className="card p-6 text-center">
            <div className="text-3xl">📬</div>
            <p className="mt-2 font-medium">Revisa tu correo</p>
            <p className="mt-1 text-sm text-muted">
              Si existe una cuenta con ese email, recibirás un enlace válido durante 1 hora.
            </p>
            <Link href="/panel/login" className="btn-ghost mt-5 inline-flex">Volver al login</Link>
          </div>
        ) : (
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
            <button type="submit" disabled={loading} className="btn-primary w-full">
              {loading ? "Enviando…" : "Enviarme el enlace"}
            </button>
            <p className="text-center text-sm text-muted">
              <Link href="/panel/login" className="font-medium text-foreground underline-offset-2 hover:underline">
                Volver al login
              </Link>
            </p>
          </form>
        )}
      </div>
    </div>
  );
}
