"use client";

// Consola de plataforma: la zona del DUEÑO DEL SAAS, no de un salón. Identidad
// propia (tema [data-console] en globals.css): índigo sobre neutros fríos,
// sin la marca del salón, para que nunca se confunda con el panel.
import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { admin, clearToken, getToken, type PanelUser } from "@/lib/admin";
import { ThemeToggle } from "@/components/ThemeToggle";

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
    <div data-console className="flex min-h-screen flex-col">
      <header className="sticky top-0 z-20 border-b border-[#262b36] bg-[#12141c] text-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-5 py-3">
          <span className="flex items-center gap-2.5 font-semibold tracking-tight">
            <span
              className="grid h-8 w-8 place-items-center rounded-lg text-base"
              style={{ background: "linear-gradient(135deg, #6366f1, #4338ca)" }}
            >
              ⚙️
            </span>
            <span>
              Consola de plataforma
              <span className="ml-2 rounded-full border border-white/20 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-white/70">
                superadmin
              </span>
            </span>
          </span>
          <div className="flex items-center gap-2 text-sm">
            <ThemeToggle />
            <Link
              href="/panel"
              className="rounded-full px-3 py-1.5 text-white/70 transition hover:bg-white/10 hover:text-white"
            >
              ← Panel del salón
            </Link>
            <span className="hidden text-white/50 sm:inline">{user?.name}</span>
            <button
              onClick={logout}
              className="rounded-full bg-white/10 px-3 py-1.5 font-medium transition hover:bg-white/20"
            >
              Salir
            </button>
          </div>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 px-5 py-8">{children}</main>
      <footer className="border-t border-border py-4 text-center text-xs text-muted">
        Zona de operaciones del SaaS · las cuentas de los salones no ven esta consola
      </footer>
    </div>
  );
}
