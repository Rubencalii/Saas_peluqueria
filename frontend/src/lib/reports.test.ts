import { describe, expect, it } from "vitest";
import { reportCsvRows, type ReportBundle } from "./reports";
import type { ReportChannel, ReportOccupancy, ReportPeak, ReportRevenue } from "./admin";

type Cell = string | number | null;

function hasRow(rows: Cell[][], target: Cell[]): boolean {
  return rows.some((r) => r.length === target.length && r.every((c, i) => c === target[i]));
}

const revenue: ReportRevenue = {
  total_revenue: 120,
  by_staff: [{ staff_id: 1, staff_name: "Ana", appointments: 3, revenue: 90 }],
  by_service: [{ service_id: 2, service_name: "Corte", appointments: 4, revenue: 120 }],
};
const channel: ReportChannel = { by_channel: { web: 5, whatsapp: 2, manual: 1 }, total: 8 };
const occupancy: ReportOccupancy = {
  booked_minutes: 600,
  capacity_minutes: 1200,
  occupancy_rate: 0.5,
  by_staff: [{ staff_id: 1, staff_name: "Ana", booked_minutes: 600, appointments: 10 }],
};
const peak: ReportPeak = { timezone: "Europe/Madrid", slots: [{ weekday: 0, hour: 10, appointments: 3 }] };

function bundle(over: Partial<ReportBundle> = {}): ReportBundle {
  return {
    from: "2026-06-01",
    to: "2026-06-30",
    revenue: null,
    channel: null,
    noShows: null,
    retention: null,
    ratings: null,
    occupancy: null,
    peak: null,
    ...over,
  };
}

describe("reportCsvRows", () => {
  it("siempre incluye periodo y los KPIs base, con valores vacíos si faltan", () => {
    const rows = reportCsvRows(bundle());
    expect(hasRow(rows, ["Periodo", "2026-06-01 a 2026-06-30"])).toBe(true);
    expect(hasRow(rows, ["Ingresos", ""])).toBe(true);
    expect(hasRow(rows, ["Tasa no-show", ""])).toBe(true);
  });

  it("vuelca el desglose de ingresos por servicio y profesional", () => {
    const rows = reportCsvRows(bundle({ revenue }));
    expect(hasRow(rows, ["Ingresos", 120])).toBe(true);
    expect(hasRow(rows, ["Corte", 4, 120])).toBe(true);
    expect(hasRow(rows, ["Ana", 3, 90])).toBe(true);
  });

  it("añade la sección de canal solo cuando hay datos", () => {
    expect(hasRow(reportCsvRows(bundle()), ["Reservas por canal", "Citas"])).toBe(false);
    const rows = reportCsvRows(bundle({ channel }));
    expect(hasRow(rows, ["Web", 5])).toBe(true);
    expect(hasRow(rows, ["WhatsApp", 2])).toBe(true);
    expect(hasRow(rows, ["Manual", 1])).toBe(true);
  });

  it("incluye ocupación total y por profesional cuando hay sede", () => {
    const rows = reportCsvRows(bundle({ occupancy }));
    expect(hasRow(rows, ["Ocupación (min reservados)", 600])).toBe(true);
    expect(hasRow(rows, ["Ocupación (%)", 0.5])).toBe(true);
    expect(hasRow(rows, ["Ana", 600, 10])).toBe(true);
  });

  it("incluye horas punta con el día de la semana legible", () => {
    const rows = reportCsvRows(bundle({ peak }));
    expect(hasRow(rows, ["Horas punta", "Hora", "Citas"])).toBe(true);
    expect(hasRow(rows, ["Lun", "10h", 3])).toBe(true);
  });

  it("omite ocupación y horas punta cuando son null (sin sede)", () => {
    const rows = reportCsvRows(bundle({ revenue }));
    expect(hasRow(rows, ["Ocupación (%)", ""])).toBe(false);
    expect(hasRow(rows, ["Horas punta", "Hora", "Citas"])).toBe(false);
  });
});
