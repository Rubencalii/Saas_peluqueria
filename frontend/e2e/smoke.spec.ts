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

/**
 * Cancela por API las citas de ese teléfono+código. El smoke corre contra la BD
 * de desarrollo, que no se resetea: sin limpiar, cada ejecución deja huecos
 * ocupados en el mismo lunes y acaba agotando la agenda del servicio (algunos
 * los ofrece un solo profesional) haciendo fallar ejecuciones futuras.
 */
async function limpiarCitas(page: Page, phone: string, code: string): Promise<void> {
  const r = await page.request.get(`/api/v1/appointments/lookup?phone=${encodeURIComponent(phone)}&code=${code}`);
  if (!r.ok()) return;
  const { appointments } = (await r.json()) as { appointments: Array<{ appointment_id: number }> };
  for (const a of appointments ?? []) {
    await page.request.delete(`/api/v1/appointments/${a.appointment_id}?code=${code}`);
  }
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
  const phone = "+34600" + String(Date.now()).slice(-6);
  const code = await bookPublicly(page, phone);
  await limpiarCitas(page, phone, code);
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

/** Entra al panel con el admin del seed y espera al dashboard. */
async function loginPanel(page: Page): Promise<void> {
  await page.goto("/panel/login");
  await page.getByLabel("Email").fill("admin@salon.es");
  await page.getByLabel("Contraseña").fill("admin1234");
  await page.getByRole("button", { name: "Entrar" }).click();
  await page.waitForURL("**/panel");
}

test("login del panel y agenda", async ({ page }) => {
  await loginPanel(page);

  // Entra al panel (dashboard) con la navegación visible.
  const agendaLink = page.locator("aside nav").getByRole("link", { name: /Agenda/ });
  await expect(agendaLink).toBeVisible({ timeout: 15_000 });

  // La agenda carga su cabecera y controles.
  await agendaLink.click();
  await expect(page.getByRole("heading", { name: "Agenda", exact: true })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "+ Nueva cita" })).toBeVisible();
});

test("alta manual de cita desde el panel", async ({ page }) => {
  await loginPanel(page);

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

  // Cliente nuevo (modo por defecto) y crear. El nombre lleva marca de tiempo:
  // la BD de dev conserva los clientes de ejecuciones anteriores y un nombre
  // fijo acabaría casando con varios en el listado.
  const stamp = String(Date.now()).slice(-6);
  const nombre = `Cliente Panel E2E ${stamp}`;
  await form.getByPlaceholder("Nombre del cliente").fill(nombre);
  await form.getByPlaceholder("Teléfono").fill("+34611" + stamp);

  const [creada] = await Promise.all([
    page.waitForResponse((r) => r.url().includes("/api/v1/admin/appointments") && r.request().method() === "POST"),
    form.getByRole("button", { name: "Crear cita" }).click(),
  ]);
  const { appointment_id: apptId } = (await creada.json()) as { appointment_id: number };

  // La agenda salta al día de la cita y la muestra en el listado.
  await expect(page.getByText(nombre)).toBeVisible({ timeout: 15_000 });

  // Se cancela para no dejar el lunes ocupado (ver limpiarCitas).
  const token = await page.evaluate(() => window.localStorage.getItem("panel_token"));
  await page.request.delete(`/api/v1/admin/appointments/${apptId}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
});

test("comisiones del profesional: se guardan y persisten", async ({ page }) => {
  await loginPanel(page);

  await page.locator("aside nav").getByRole("link", { name: /Personal/ }).click();
  await page.locator("ul button.card").first().click(); // abrir la ficha del primero

  const card = page.locator(".card", { hasText: "Comisiones" });
  await expect(card).toBeVisible({ timeout: 15_000 });

  const general = card.getByLabel(/Comisión general/);
  await general.fill("35");
  await card.getByRole("button", { name: "Guardar comisiones" }).click();
  await expect(card.getByText("Comisiones guardadas.")).toBeVisible({ timeout: 15_000 });

  // Al volver a abrir la ficha, la comisión sigue ahí.
  await page.reload();
  await page.locator("ul button.card").first().click();
  await expect(page.locator(".card", { hasText: "Comisiones" }).getByLabel(/Comisión general/)).toHaveValue("35", {
    timeout: 15_000,
  });
});
