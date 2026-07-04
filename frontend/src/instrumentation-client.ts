// Monitorización de errores en el navegador. Sin DSN queda desactivada.
// Los eventos viajan por el túnel same-origin /monitoring (compatible con la
// CSP: connect-src 'self'), configurado en next.config.ts.
import * as Sentry from "@sentry/nextjs";

const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN ?? "";

Sentry.init({
  dsn,
  enabled: dsn !== "",
  tracesSampleRate: 0,
  sendDefaultPii: false,
});

export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
