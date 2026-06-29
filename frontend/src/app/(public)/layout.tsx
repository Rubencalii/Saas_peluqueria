import Link from "next/link";
import { api } from "@/lib/api";
import { brandCss, brandName, type Branding } from "@/lib/theme";
import { ThemeToggle } from "@/components/ThemeToggle";

// Cada host (subdominio) es una cuenta distinta: render por petición, no estático.
export const dynamic = "force-dynamic";

export default async function PublicLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  let branding: Branding | null = null;
  try {
    branding = (await api.branding()).branding;
  } catch {
    branding = null;
  }
  const css = brandCss(branding);
  const name = brandName(branding);

  return (
    <div className="flex min-h-full flex-col">
      {css ? <style dangerouslySetInnerHTML={{ __html: css }} /> : null}

      <header className="sticky top-0 z-20 border-b border-border/70 bg-card/80 backdrop-blur-md">
        <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3.5">
          <Link href="/" className="flex items-center gap-2 font-semibold tracking-tight">
            {branding?.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={branding.logo_url} alt={name} className="h-8 w-8 rounded-full object-cover" />
            ) : (
              <span
                className="grid h-8 w-8 place-items-center rounded-full text-brand-ink"
                style={{ background: "var(--brand)" }}
              >
                ✂️
              </span>
            )}
            <span className="text-[15px]">{name}</span>
          </Link>
          <div className="flex items-center gap-1">
            <ThemeToggle />
            <Link
              href="/mi-cita"
              className="rounded-full px-3.5 py-1.5 text-sm font-medium text-muted transition hover:bg-brand-soft hover:text-foreground"
            >
              Mi cita
            </Link>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-8">{children}</main>

      <footer className="border-t border-border/70 py-6 text-center text-xs text-muted">
        Reservas online · Te confirmamos por WhatsApp 💬
      </footer>
    </div>
  );
}
