"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { admin, clearToken, getToken, type PanelUser } from "@/lib/admin";
import { brandName, brandVars, type Branding } from "@/lib/theme";

const NAV = [
  { href: "/panel/agenda", label: "Agenda", icon: "📅" },
  { href: "/panel/clientes", label: "Clientes", icon: "👥" },
  { href: "/panel/cuenta", label: "Cuenta", icon: "💳" },
  { href: "/panel/apariencia", label: "Apariencia", icon: "🎨" },
];

export default function PanelLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const isLogin = pathname === "/panel/login";

  const [user, setUser] = useState<PanelUser | null>(null);
  const [branding, setBranding] = useState<Branding | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (isLogin) {
      setReady(true);
      return;
    }
    if (!getToken()) {
      router.replace("/panel/login");
      return;
    }
    admin
      .me()
      .then((r) => {
        setUser(r.user);
        setReady(true);
      })
      .catch(() => {
        // adminFetch ya redirige en 401.
      });
    admin
      .branding()
      .then((r) => setBranding(r.branding))
      .catch(() => {});
  }, [isLogin, pathname, router]);

  if (isLogin) return <>{children}</>;

  if (!ready) {
    return <div className="grid min-h-screen place-items-center text-sm text-muted">Cargando…</div>;
  }

  async function logout() {
    try {
      await admin.logout();
    } catch {
      /* da igual: limpiamos igualmente */
    }
    clearToken();
    router.replace("/panel/login");
  }

  return (
    <div className="flex min-h-screen flex-col md:flex-row" style={brandVars(branding)}>
      <aside className="border-b border-border bg-card md:w-60 md:shrink-0 md:border-b-0 md:border-r">
        <div className="flex items-center gap-2 px-5 py-4">
          {branding?.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={branding.logo_url} alt="" className="h-8 w-8 rounded-full object-cover" />
          ) : (
            <span
              className="grid h-8 w-8 place-items-center rounded-full text-brand-ink"
              style={{ background: "var(--brand)" }}
            >
              ✂️
            </span>
          )}
          <span className="truncate font-semibold tracking-tight">{brandName(branding, "Panel")}</span>
        </div>
        <nav className="flex gap-1 px-3 pb-3 md:flex-col">
          {NAV.map((item) => {
            const active = pathname.startsWith(item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                className={
                  "flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition " +
                  (active ? "bg-brand-soft text-foreground" : "text-muted hover:bg-brand-soft/60")
                }
              >
                <span>{item.icon}</span>
                {item.label}
              </Link>
            );
          })}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-border bg-card/80 px-5 py-3 backdrop-blur">
          <span className="text-sm text-muted">
            {user ? (
              <>
                Hola, <span className="font-medium text-foreground">{user.name}</span>
              </>
            ) : null}
          </span>
          <button onClick={logout} className="rounded-full px-3 py-1.5 text-sm font-medium text-muted hover:bg-brand-soft hover:text-foreground">
            Cerrar sesión
          </button>
        </header>

        <main className="flex-1 p-5 md:p-8">
          <div className="mx-auto max-w-4xl">{children}</div>
        </main>
      </div>
    </div>
  );
}
