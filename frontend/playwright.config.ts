import { defineConfig } from "@playwright/test";

// Smoke E2E local (npm run e2e): recorre los flujos críticos en un navegador
// real. Arranca solo el backend (PHP embebido) y el frontend (next dev);
// REQUIERE la BD de desarrollo levantada: `docker compose up -d` en la raíz.
// Corre contra la BD de desarrollo: crea citas reales con clientes E2E.
export default defineConfig({
  testDir: "./e2e",
  timeout: 60_000,
  use: {
    baseURL: "http://localhost:3000",
    locale: "es-ES",
  },
  webServer: [
    {
      // Servidor embebido de PHP con index.php como router (solo API).
      command: "php -S 127.0.0.1:8000 -t ../backend/public ../backend/public/index.php",
      url: "http://127.0.0.1:8000/api/v1/health",
      reuseExistingServer: true,
      timeout: 30_000,
    },
    {
      command: "npm run dev",
      url: "http://localhost:3000",
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
