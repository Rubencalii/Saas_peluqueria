import { defineConfig } from "@playwright/test";

// Smoke E2E local (npm run e2e): recorre los flujos críticos en un navegador
// real. Arranca solo el backend (PHP embebido) y el frontend (next dev);
// REQUIERE la BD de desarrollo levantada: `docker compose up -d` en la raíz.
// Corre contra la BD de desarrollo: crea citas reales con clientes E2E.
//
// Los puertos son configurables por si 8000/3000 están ocupados por otra cosa:
// `E2E_API_PORT=8010 E2E_WEB_PORT=3010 npm run e2e` (en PowerShell, $env:...).
const apiPort = process.env.E2E_API_PORT ?? "8000";
const webPort = process.env.E2E_WEB_PORT ?? "3000";
const apiBase = `http://127.0.0.1:${apiPort}`;
const baseURL = `http://localhost:${webPort}`;

export default defineConfig({
  testDir: "./e2e",
  timeout: 60_000,
  use: {
    baseURL,
    locale: "es-ES",
  },
  webServer: [
    {
      // Servidor embebido de PHP con index.php como router (solo API).
      command: `php -S 127.0.0.1:${apiPort} -t ../backend/public ../backend/public/index.php`,
      url: `${apiBase}/api/v1/health`,
      reuseExistingServer: true,
      timeout: 30_000,
    },
    {
      command: `npm run dev -- --port ${webPort}`,
      url: baseURL,
      // El frontend habla con el backend que acaba de arrancar, no con el 8000
      // por defecto de next.config.ts.
      env: { API_BASE: apiBase },
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
