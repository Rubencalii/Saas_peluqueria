import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { Service } from "@/lib/types";

// Stripe queda fuera del test (no hay depósito en este servicio igualmente).
vi.mock("@/components/Deposit", () => ({ Deposit: () => null }));

vi.mock("@/lib/api", () => {
  class ApiError extends Error {
    constructor(public code: string, message: string, public status: number) {
      super(message);
    }
  }
  return {
    ApiError,
    api: { availability: vi.fn(), createAppointment: vi.fn() },
  };
});

import { api } from "@/lib/api";
import { BookingFlow } from "@/components/BookingFlow";

const mocked = vi.mocked(api, true);

const services: Service[] = [
  { id: 1, name: "Corte mujer", duration_min: 45, buffer_min: 0, price: 18, deposit_amount: null, description: "Lavado y corte" },
];

describe("BookingFlow", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocked.availability.mockResolvedValue({
      date: "2026-07-15",
      slots: [{ start: "2026-07-15T08:30:00.000Z", staff_id: 3 }], // 10:30 en Madrid
    });
    mocked.createAppointment.mockResolvedValue({
      appointment_id: 99,
      status: "confirmada",
      staff_id: 3,
      start: "2026-07-15T08:30:00.000Z",
      end: "2026-07-15T09:15:00.000Z",
      public_code: "ABC123",
    });
  });

  it("reserva de principio a fin: servicio → hueco → datos → confirmación", async () => {
    render(<BookingFlow locationId={1} timeZone="Europe/Madrid" services={services} />);

    // 1) Elegir servicio.
    fireEvent.click(screen.getByText("Corte mujer"));

    // 2) Aparecen los huecos (disponibilidad cargada) → elegir 10:30.
    fireEvent.click(await screen.findByText("10:30"));

    // 3) Datos del cliente.
    fireEvent.change(screen.getByLabelText("Nombre y apellidos"), { target: { value: "Ana" } });
    fireEvent.change(screen.getByLabelText("Teléfono"), { target: { value: "+34600000000" } });
    fireEvent.click(screen.getByText("Confirmar cita"));

    // 4) Confirmación con el código.
    expect(await screen.findByText("¡Cita confirmada!")).toBeTruthy();
    expect(screen.getByText("ABC123")).toBeTruthy();

    expect(mocked.createAppointment).toHaveBeenCalledTimes(1);
    const [body] = mocked.createAppointment.mock.calls[0];
    expect(body).toMatchObject({
      location_id: 1,
      service_id: 1,
      start: "2026-07-15T08:30:00.000Z",
      customer: { name: "Ana", phone: "+34600000000" },
    });
  });
});
