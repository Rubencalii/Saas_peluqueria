import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("@/lib/admin", () => ({
  admin: {
    services: vi.fn().mockResolvedValue({
      services: [
        {
          id: 1,
          name: "Corte mujer",
          duration_min: 45,
          buffer_min: 0,
          price: 18,
          deposit_amount: null,
          description: null,
          segments: [],
          locations: [{ location_id: 1, price_override: null }],
        },
      ],
    }),
    locations: vi.fn().mockResolvedValue({
      locations: [{ id: 1, name: "Salón Centro", slug: "centro", address: null, phone: null, timezone: "Europe/Madrid", active: true }],
    }),
    createService: vi.fn(),
    updateService: vi.fn(),
  },
}));

import ServiciosPage from "@/app/panel/servicios/page";

describe("Panel · Servicios", () => {
  it("lista los servicios y abre el editor de alta", async () => {
    render(<ServiciosPage />);

    // El servicio del catálogo aparece con su sede.
    expect(await screen.findByText("Corte mujer")).toBeTruthy();
    expect(screen.getByText(/Salón Centro/)).toBeTruthy();

    // Abrir "Nuevo servicio" muestra el editor.
    fireEvent.click(screen.getByText("+ Nuevo servicio"));
    expect(screen.getByText("Nuevo servicio")).toBeTruthy();
    expect(screen.getByText("Se ofrece en")).toBeTruthy();
  });
});
