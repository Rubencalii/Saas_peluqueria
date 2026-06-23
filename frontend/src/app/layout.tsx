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
        <header className="border-b border-border bg-card">
          <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
            <Link href="/" className="text-lg font-semibold tracking-tight">
              ✂️ Reservas
            </Link>
            <Link
              href="/mi-cita"
              className="text-sm font-medium text-muted hover:text-foreground"
            >
              Mi cita
            </Link>
          </div>
        </header>

        <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-6">{children}</main>

        <footer className="border-t border-border py-6 text-center text-xs text-muted">
          Reservas online · Te confirmamos por WhatsApp
        </footer>
      </body>
    </html>
  );
}
