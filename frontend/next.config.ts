import type { NextConfig } from "next";
import { withSentryConfig } from "@sentry/nextjs";

// La API del backend (Symfony). En el navegador llamamos a rutas relativas
// `/api/...` que Next reescribe al backend → mismo origen, sin CORS en dev.
const API_BASE = process.env.API_BASE ?? "http://localhost:8000";

// Cabeceras de seguridad para todas las páginas servidas por Next.
// La CSP es estricta en scripts; permite estilos inline porque Tailwind v4 y
// los estilos `style={{…}}` (variables de marca, color-mix) los necesitan.
// `connect-src` admite el mismo origen (las llamadas a la API son relativas
// `/api/…` reescritas al backend) y Stripe para el checkout.
const csp = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline' https://js.stripe.com",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob: https:",
  "font-src 'self' data:",
  "connect-src 'self' https://api.stripe.com",
  "frame-src https://js.stripe.com https://hooks.stripe.com",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

const securityHeaders = [
  { key: "Content-Security-Policy", value: csp },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
];

const nextConfig: NextConfig = {
  // La imagen Docker de producción usa el runtime compacto (server.js).
  // Se activa solo en el build de la imagen para no cambiar `next start` local.
  ...(process.env.NEXT_OUTPUT === "standalone" ? { output: "standalone" as const } : {}),
  async rewrites() {
    return [{ source: "/api/:path*", destination: `${API_BASE}/api/:path*` }];
  },
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

// Sentry (monitorización de errores). Sin DSN el SDK queda desactivado; el
// túnel /monitoring hace que el navegador envíe al mismo origen (CSP intacta).
// Sin SENTRY_AUTH_TOKEN no se suben sourcemaps (aviso silenciado): correcto
// en local/CI; en producción puede añadirse el token para stack traces legibles.
export default withSentryConfig(nextConfig, {
  silent: true,
  tunnelRoute: "/monitoring",
  disableLogger: true,
});
