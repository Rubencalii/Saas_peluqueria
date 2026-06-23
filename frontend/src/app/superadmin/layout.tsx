"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { admin, clearToken, getToken, type PanelUser } from "@/lib/admin";

export default function SuperAdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [state, setState] = useState<"loading" | "ok" | "forbidden">("loading");
  const [user, setUser] = useState<PanelUser | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/panel/login");
      return;
    }
    admin
      .me()
      .then((r) => {
        setUser(r.user);
        setState(r.user.is_superadmin ? "ok" : "forbidden");
      })
      .catch(() => {});
  }, [router]);

  function logout() {
    admin.logout().catch(() => {});
    clearToken();
    router.replace("/panel/login");
  }

  if (state === "loading") {
    return <div className="grid min-h-screen place-items-center text-sm text-muted">Cargando…</div>;
  }

  if (state === "forbidden") {
    return (
      <div className="grid min-h-screen place-items-center px-4 text-center">
        <div>
          <p className="text-4xl">🚫</p>
          <h1 className="mt-2 text-xl font-bold">Acceso restringido</h1>
          <p className="mt-1 text-sm text-muted">Esta zona es solo para el administrador de plataforma.</p>
          <Link href="/panel" className="btn-primary mt-4 inline-flex">Ir a mi panel</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen">
      <header className="border-b border-border bg-foreground text-[var(--background)]">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-5 py-3">
          <span className="flex items-center gap-2 font-semibold tracking-tight">
            <span className="grid h-7 w-7 place-items-center rounded-full bg-[var(--background)] text-foreground">⚙️</span>
            Plataforma
          </span>
          <div className="flex items-center gap-3 text-sm">
            <Link href="/panel" className="opacity-80 hover:opacity-100">
              Panel
            </Link>
            <span className="opacity-50">·</span>
            <span className="opacity-80">{user?.name}</span>
            <button onClick={logout} className="rounded-full bg-[var(--background)]/15 px-3 py-1 hover:bg-[var(--background)]/25">
              Salir
            </button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-5 py-8">{children}</main>
    </div>
  );
}
