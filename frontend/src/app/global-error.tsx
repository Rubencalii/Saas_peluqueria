"use client";

// Última red de seguridad del App Router: captura errores de render de la
// raíz, los reporta a Sentry (si hay DSN) y muestra un mensaje amable.
import * as Sentry from "@sentry/nextjs";
import { useEffect } from "react";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    Sentry.captureException(error);
  }, [error]);

  return (
    <html lang="es">
      <body style={{ fontFamily: "system-ui, sans-serif", display: "grid", placeItems: "center", minHeight: "100vh", margin: 0, background: "#fbf8f4", color: "#241d17" }}>
        <div style={{ textAlign: "center", padding: "2rem" }}>
          <div style={{ fontSize: "2.5rem" }}>💇</div>
          <h1 style={{ margin: "0.75rem 0 0.25rem", fontSize: "1.4rem" }}>Vaya, algo ha fallado</h1>
          <p style={{ color: "#837568", margin: 0 }}>Ya estamos avisados. Prueba a recargar.</p>
          <button
            onClick={reset}
            style={{
              marginTop: "1.25rem",
              padding: "0.7rem 1.6rem",
              borderRadius: "999px",
              border: "none",
              background: "#a96f43",
              color: "#fffaf5",
              fontWeight: 600,
              cursor: "pointer",
            }}
          >
            Reintentar
          </button>
        </div>
      </body>
    </html>
  );
}
