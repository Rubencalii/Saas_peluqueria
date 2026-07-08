import { expect, test, type Page } from "@playwright/test";

// Smoke E2E de los flujos críticos (doc 10 §1): reserva pública, autogestión
// en Mi cita (reprogramar/cancelar) y panel (login, agenda y alta manual).
// Usa el seed de la BD de desarrollo (sedes/servicios/horarios y admin@salon.es).

/** Próximo lunes (el seed siempre tiene agenda los lunes), +N semanas. */
function nextMonday(weeksAhead = 0): string {
  const d = new Date();
  d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7) + weeksAhead * 7);
  return d.toISOString().slice(0, 10);
}

/** Reserva pública completa; devuelve el código de gestión de la cita. */
async function bookPublicly(page: Page, phone: string): Promise<string> {
  await page.goto("/");
  await page.locator("a.card-link").first().click(); // primer salón
  await page.locator("button.card-link").first().click(); // primer servicio

  await page.locator('input[type="date"]').fill(nextMonday());
  const slot = page.locator("button.slot").first();
  await expect(slot).toBeVisible({ timeout: 15_000 });
  await slot.click();

  await page.getByLabel("Nombre y apellidos").fill("Cliente E2E");
  await page.getByLabel("Teléfono").fill(phone);
  await page.getByRole("button", { name: "Confirmar cita" }).click();

  await expect(page.getByText("¡Cita confirmada!")).toBeVisible({ timeout: 15_000 });
  const code = (await page.locator(".font-mono").textContent()) ?? "";
  expect(code).toMatch(/^[0-9a-f]{16}$/);

  return code;
}

test("reserva pública de punta a punta", async ({ page }) => {
  await bookPublicly(page, "+34600" + String(Date.now()).slice(-6));
});

test("mi cita: consultar, reprogramar y cancelar", async ({ page }) => {
  const phone = "+34622" + String(Date.now()).slice(-6);
  const code = await bookPublicly(page, phone);

  // Buscar la cita con teléfono + código.
  await page.goto("/mi-cita");
  await page.getByLabel("Teléfono").fill(phone);
  await page.getByLabel("Código de cita").fill(code);
  await page.getByRole("button", { name: "Buscar mi cita" }).click();
  await expect(page.getByRole("button", { name: "Reprogramar" })).toBeVisible({ timeout: 15_000 });

  // Reprogramar al lunes siguiente, primer hueco.
  await page.getByRole("button", { name: "Reprogramar" }).click();
  await page.getByLabel("Nuevo día").fill(nextMonday(1));
  const slot = page.locator("button.slot").first();
  await expect(slot).toBeVisible({ timeout: 15_000 });
  await slot.click();

  // Tras reprogramar, la lista se recarga y la cita sigue gestionable.
  await expect(page.getByRole("button", { name: "Reprogramar" })).toBeVisible({ timeout: 15_000 });

  // Cancelar (acepta el confirm del navegador) → sin próximas citas.
  page.on("dialog", (d) => void d.accept());
  await page.getByRole("button", { name: "Cancelar", exact: true }).click();
  await expect(page.getByText(/no tienes próximas citas/)).toBeVisible({ timeout: 15_000 });
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

test("alta manual de cita desde el panel", async ({ page }) => {
  // Login.
  await page.goto("/panel/login");
  await page.getByLabel("Email").fill("admin@salon.es");
  await page.getByLabel("Contraseña").fill("admin1234");
  await page.getByRole("button", { name: "Entrar" }).click();
  await page.waitForURL("**/panel");

  // Ir a la agenda y abrir "Nueva cita".
  await page.locator("aside nav").getByRole("link", { name: /Agenda/ }).click();
  await page.getByRole("button", { name: "+ Nueva cita" }).click();

  const form = page.locator(".card", { hasText: "Nueva cita" });
  await expect(form).toBeVisible({ timeout: 15_000 });

  // Servicio + día (lunes con agenda en el seed).
  await form.getByLabel("Servicio").selectOption({ index: 1 });
  await form.locator('input[type="date"]').fill(nextMonday());

  // Primer hueco ofrecido.
  const slot = form.locator("button.slot").first();
  await expect(slot).toBeVisible({ timeout: 15_000 });
  await slot.click();

  // Cliente nuevo (modo por defecto) y crear.
  await form.getByPlaceholder("Nombre del cliente").fill("Cliente Panel E2E");
  await form.getByPlaceholder("Teléfono").fill("+34611" + String(Date.now()).slice(-6));
  await form.getByRole("button", { name: "Crear cita" }).click();

  // La cita aparece en el listado del día.
  await expect(page.getByText("Cliente Panel E2E")).toBeVisible({ timeout: 15_000 });
});
