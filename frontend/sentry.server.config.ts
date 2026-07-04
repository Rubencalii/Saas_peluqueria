// Monitorización de errores del servidor Next (SSR/route handlers).
// Sin NEXT_PUBLIC_SENTRY_DSN queda desactivado (dev/local), como el resto
// de integraciones del proyecto.
import * as Sentry from "@sentry/nextjs";

const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN ?? "";

Sentry.init({
  dsn,
  enabled: dsn !== "",
  environment: process.env.NODE_ENV,
  // Solo errores; sin trazas de rendimiento.
  tracesSampleRate: 0,
  // Sin datos personales (RGPD, doc 09).
  sendDefaultPii: false,
});
