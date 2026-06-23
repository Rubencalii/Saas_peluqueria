import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Reservas",
  description: "Reservas para peluquerías.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es" className="h-full antialiased">
      <body className="min-h-full">{children}</body>
    </html>
  );
}
