import type { Metadata } from "next";
import Link from "next/link";
import "./globals.css";

export const metadata: Metadata = {
  title: "Reserva tu cita",
  description: "Reserva tu cita en la peluquería de forma rápida y sin llamadas.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es" className="h-full antialiased">
      <body className="min-h-full flex flex-col">
        <header className="sticky top-0 z-20 border-b border-border/70 bg-card/80 backdrop-blur-md">
          <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3.5">
            <Link href="/" className="flex items-center gap-2 font-semibold tracking-tight">
              <span
                className="grid h-8 w-8 place-items-center rounded-full text-brand-ink"
                style={{ background: "var(--brand)" }}
              >
                ✂️
              </span>
              <span className="text-[15px]">Reservas</span>
            </Link>
            <Link
              href="/mi-cita"
              className="rounded-full px-3.5 py-1.5 text-sm font-medium text-muted transition hover:bg-brand-soft hover:text-foreground"
            >
              Mi cita
            </Link>
          </div>
        </header>

        <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-8">{children}</main>

        <footer className="border-t border-border/70 py-6 text-center text-xs text-muted">
          Reservas online · Te confirmamos por WhatsApp 💬
        </footer>
      </body>
    </html>
  );
}
