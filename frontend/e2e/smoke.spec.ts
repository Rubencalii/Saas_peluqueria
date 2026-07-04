import { expect, test } from "@playwright/test";

// Smoke E2E de los dos flujos críticos: la reserva pública de punta a punta
// (doc 10 §1) y el acceso al panel. Usa el seed de la BD de desarrollo
// (sedes/servicios/horarios y admin@salon.es).

/** Próximo lunes (el seed siempre tiene agenda los lunes). */
function nextMonday(): string {
  const d = new Date();
  d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7));
  return d.toISOString().slice(0, 10);
}

test("reserva pública de punta a punta", async ({ page }) => {
  await page.goto("/");

  // Home: elegir el primer salón.
  await page.locator("a.card-link").first().click();

  // Paso 1: servicio.
  await page.locator("button.card-link").first().click();

  // Paso 2: día (lunes) y primer hueco libre.
  await page.locator('input[type="date"]').fill(nextMonday());
  const slot = page.locator("button.slot").first();
  await expect(slot).toBeVisible({ timeout: 15_000 });
  await slot.click();

  // Paso 3: datos del cliente (teléfono único por ejecución).
  await page.getByLabel("Nombre y apellidos").fill("Cliente E2E");
  await page.getByLabel("Teléfono").fill("+34600" + String(Date.now()).slice(-6));
  await page.getByRole("button", { name: "Confirmar cita" }).click();

  // Confirmación con su código de gestión.
  await expect(page.getByText("¡Cita confirmada!")).toBeVisible({ timeout: 15_000 });
  await expect(page.locator(".font-mono")).toHaveText(/^[0-9a-f]{16}$/);
});

test("login del panel y agenda", async ({ page }) => {
  await page.goto("/panel/login");

  await page.getByLabel("Email").fill("admin@salon.es");
  await page.getByLabel("Contraseña").fill("admin1234");
  await page.getByRole("button", { name: "Entrar" }).click();

  // Entra al panel (dashboard) con la navegación visible.
  await page.waitForURL("**/panel");
  const agendaLink = page.locator("aside nav").getByRole("link", { name: /Agenda/ });
  await expect(agendaLink).toBeVisible({ timeout: 15_000 });

  // La agenda carga su cabecera y controles.
  await agendaLink.click();
  await expect(page.getByRole("heading", { name: "Agenda", exact: true })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "+ Nueva cita" })).toBeVisible();
});
