"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { admin } from "@/lib/admin";

export default function VerificarPage() {
  const [state, setState] = useState<"loading" | "ok" | "error">("loading");

  useEffect(() => {
    const token = new URLSearchParams(window.location.search).get("token") ?? "";
    if (!token) {
      setState("error");
      return;
    }
    admin
      .verifyEmail(token)
      .then(() => setState("ok"))
      .catch(() => setState("error"));
  }, []);

  return (
    <div className="card mx-auto max-w-md p-8 text-center">
      {state === "loading" ? (
        <p className="text-muted">Verificando…</p>
      ) : state === "ok" ? (
        <>
          <div className="mx-auto grid h-14 w-14 place-items-center rounded-full text-2xl text-white" style={{ background: "var(--accent)" }}>
            ✓
          </div>
          <h1 className="mt-3 text-2xl font-bold tracking-tight">¡Email verificado!</h1>
          <p className="mt-1 text-muted">Tu cuenta ya está confirmada.</p>
          <Link href="/panel" className="btn-primary mt-5 inline-flex">Ir al panel</Link>
        </>
      ) : (
        <>
          <div className="text-4xl">⚠️</div>
          <h1 className="mt-2 text-xl font-bold">Enlace no válido o caducado</h1>
          <p className="mt-1 text-sm text-muted">Pide uno nuevo desde el panel (banner «Verifica tu email»).</p>
          <Link href="/panel" className="btn-ghost mt-5 inline-flex">Ir al panel</Link>
        </>
      )}
    </div>
  );
}
