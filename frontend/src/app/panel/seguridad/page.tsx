"use client";

import { useEffect, useState } from "react";
import { admin } from "@/lib/admin";

export default function SeguridadPage() {
  const [enabled, setEnabled] = useState<boolean | null>(null);
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    admin
      .twoFactor()
      .then((r) => setEnabled(r.enabled))
      .catch(() => setMsg({ ok: false, text: "No se pudo cargar el estado." }));
  }, []);

  async function startSetup() {
    setBusy(true);
    setMsg(null);
    try {
      setSetup(await admin.twoFactorSetup());
      setCode("");
    } catch {
      setMsg({ ok: false, text: "No se pudo iniciar la configuración." });
    } finally {
      setBusy(false);
    }
  }

  async function enable() {
    if (!setup) return;
    setBusy(true);
    setMsg(null);
    try {
      await admin.twoFactorEnable(setup.secret, code.trim());
      setEnabled(true);
      setSetup(null);
      setCode("");
      setMsg({ ok: true, text: "Doble factor activado. A partir de ahora el login pedirá el código." });
    } catch (e) {
      setMsg({ ok: false, text: e instanceof Error ? e.message : "Código incorrecto." });
    } finally {
      setBusy(false);
    }
  }

  async function disable() {
    setBusy(true);
    setMsg(null);
    try {
      await admin.twoFactorDisable(code.trim());
      setEnabled(false);
      setCode("");
      setMsg({ ok: true, text: "Doble factor desactivado." });
    } catch (e) {
      setMsg({ ok: false, text: e instanceof Error ? e.message : "Código incorrecto." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Seguridad</h1>
        <p className="mt-1 text-sm text-muted">
          Protege tu acceso al panel con un segundo factor (app de autenticación).
        </p>
      </header>

      {msg ? (
        <p className={"rounded-xl px-3 py-2 text-sm " + (msg.ok ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
          {msg.text}
        </p>
      ) : null}

      {enabled === null ? (
        <div className="skeleton h-40" />
      ) : enabled ? (
        <section className="card space-y-4 p-5">
          <div className="flex items-center gap-3">
            <span className="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 text-lg">🔐</span>
            <div>
              <p className="font-semibold">Doble factor activado</p>
              <p className="text-sm text-muted">Tu login exige contraseña + código de la app.</p>
            </div>
          </div>
          <div className="rounded-xl border border-border p-4">
            <p className="text-sm font-semibold">Desactivar</p>
            <p className="mt-1 text-sm text-muted">
              Introduce un código vigente de tu app (así, si alguien roba tu sesión, no puede quitarte el 2FA).
            </p>
            <div className="mt-2 flex gap-2">
              <input
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="123456"
                inputMode="numeric"
                maxLength={6}
                className="field mt-0 w-36 text-center font-mono tracking-[0.3em]"
              />
              <button onClick={disable} disabled={busy || code.trim().length !== 6} className="btn-ghost text-red-700">
                Desactivar 2FA
              </button>
            </div>
          </div>
        </section>
      ) : setup ? (
        <section className="card space-y-4 p-5">
          <h2 className="font-semibold">Configura tu app de autenticación</h2>
          <ol className="list-decimal space-y-3 pl-5 text-sm">
            <li>
              Abre tu app (Google Authenticator, Aegis, 1Password…) y añade una cuenta nueva.
              <span className="mt-1 block text-muted">
                En el móvil puedes usar el enlace directo:{" "}
                <a href={setup.otpauth_uri} className="font-medium text-foreground underline underline-offset-2">
                  añadir al autenticador
                </a>
              </span>
            </li>
            <li>
              O introduce esta clave a mano:
              <code className="mt-1 block w-fit rounded-lg bg-brand-soft px-3 py-1.5 font-mono text-sm tracking-wider">
                {setup.secret.match(/.{1,4}/g)?.join(" ")}
              </code>
            </li>
            <li>
              Escribe el código de 6 dígitos que muestra la app para confirmar:
              <div className="mt-2 flex gap-2">
                <input
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="123456"
                  inputMode="numeric"
                  maxLength={6}
                  autoFocus
                  className="field mt-0 w-36 text-center font-mono tracking-[0.3em]"
                />
                <button onClick={enable} disabled={busy || code.trim().length !== 6} className="btn-primary px-5 py-2">
                  Activar 2FA
                </button>
                <button onClick={() => { setSetup(null); setCode(""); }} className="btn-ghost">
                  Cancelar
                </button>
              </div>
            </li>
          </ol>
          <p className="text-xs text-muted">
            La clave no se guarda hasta que confirmes un código válido: si cierras aquí, no cambia nada.
          </p>
        </section>
      ) : (
        <section className="card space-y-4 p-5">
          <div className="flex items-center gap-3">
            <span className="grid h-10 w-10 place-items-center rounded-full bg-brand-soft text-lg">🔓</span>
            <div>
              <p className="font-semibold">Doble factor desactivado</p>
              <p className="text-sm text-muted">
                Tu cuenta solo está protegida por la contraseña. Recomendado activarlo, sobre todo para
                administradores.
              </p>
            </div>
          </div>
          <button onClick={startSetup} disabled={busy} className="btn-primary w-fit">
            Activar doble factor
          </button>
        </section>
      )}
    </div>
  );
}
