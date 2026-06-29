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
      <head>
        {/* Aplica el tema guardado antes de pintar para evitar parpadeo. */}
        <script
          dangerouslySetInnerHTML={{
            __html:
              "try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}",
          }}
        />
      </head>
      <body className="min-h-full">{children}</body>
    </html>
  );
}
