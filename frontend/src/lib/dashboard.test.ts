import { describe, expect, it } from "vitest";
import { aggregateOccupancy, nextAppointment, upcomingAppointments, type DashItem } from "./dashboard";

function item(partial: Partial<DashItem> & { appointment_id: number; start: string; status: string }): DashItem {
  return {
    end: partial.start,
    channel: "panel",
    notes: null,
    public_code: null,
    service: { id: 1, name: "Corte", duration_min: 30 },
    staff: { id: 1, name: "Ana" },
    customer: { id: 1, name: "Cliente", phone: "600" },
    locationName: "Centro",
    timeZone: "Europe/Madrid",
    ...partial,
  };
}

const NOW = Date.parse("2026-07-15T10:00:00Z");

describe("upcomingAppointments", () => {
  it("ordena por hora y descarta las que ya pasaron", () => {
    const items = [
      item({ appointment_id: 1, start: "2026-07-15T12:00:00Z", status: "confirmada" }),
      item({ appointment_id: 2, start: "2026-07-15T09:00:00Z", status: "confirmada" }), // pasada
      item({ appointment_id: 3, start: "2026-07-15T11:00:00Z", status: "pendiente" }),
    ];
    const out = upcomingAppointments(items, NOW);
    expect(out.map((a) => a.appointment_id)).toEqual([3, 1]);
  });

  it("excluye canceladas, completadas y no-show", () => {
    const items = [
      item({ appointment_id: 1, start: "2026-07-15T11:00:00Z", status: "cancelada" }),
      item({ appointment_id: 2, start: "2026-07-15T12:00:00Z", status: "completada" }),
      item({ appointment_id: 3, start: "2026-07-15T13:00:00Z", status: "no_show" }),
      item({ appointment_id: 4, start: "2026-07-15T14:00:00Z", status: "confirmada" }),
    ];
    expect(upcomingAppointments(items, NOW).map((a) => a.appointment_id)).toEqual([4]);
  });

  it("respeta el límite", () => {
    const items = Array.from({ length: 10 }, (_, i) =>
      item({ appointment_id: i + 1, start: `2026-07-15T${String(11 + i).padStart(2, "0")}:00:00Z`, status: "confirmada" }),
    );
    expect(upcomingAppointments(items, NOW, 3)).toHaveLength(3);
  });
});

describe("nextAppointment", () => {
  it("devuelve la cita activa más cercana en el futuro", () => {
    const r = nextAppointment(
      [
        { status: "confirmada", start: "2026-07-15T14:00:00Z" },
        { status: "pendiente", start: "2026-07-15T11:30:00Z" },
        { status: "confirmada", start: "2026-07-15T09:00:00Z" }, // pasada
      ],
      NOW,
    );
    expect(r?.start).toBe("2026-07-15T11:30:00Z");
  });

  it("ignora canceladas/completadas y devuelve null si no hay futuras", () => {
    expect(
      nextAppointment(
        [
          { status: "cancelada", start: "2026-07-15T18:00:00Z" },
          { status: "completada", start: "2026-07-15T19:00:00Z" },
        ],
        NOW,
      ),
    ).toBeNull();
  });
});

describe("aggregateOccupancy", () => {
  it("suma reservado y capacidad de todas las sedes", () => {
    const r = aggregateOccupancy([
      { booked_minutes: 120, capacity_minutes: 480 },
      { booked_minutes: 60, capacity_minutes: 240 },
    ]);
    expect(r).toBeCloseTo(180 / 720);
  });

  it("devuelve null si no hay capacidad", () => {
    expect(aggregateOccupancy([{ booked_minutes: 0, capacity_minutes: 0 }])).toBeNull();
    expect(aggregateOccupancy([])).toBeNull();
  });
});
