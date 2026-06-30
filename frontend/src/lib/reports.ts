// Construcción (pura) de las filas del CSV de informes, separada de la página
// para poder testearla. Las celdas son texto/número/null tal como las espera
// toCsv; el escapado y la protección anti-fórmula viven en lib/csv.

import type {
  ReportChannel,
  ReportNoShows,
  ReportOccupancy,
  ReportPeak,
  ReportRatings,
  ReportRetention,
  ReportRevenue,
} from "./admin";

const DAYS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];

export interface ReportBundle {
  from: string;
  to: string;
  revenue: ReportRevenue | null;
  channel: ReportChannel | null;
  noShows: ReportNoShows | null;
  retention: ReportRetention | null;
  ratings: ReportRatings | null;
  occupancy: ReportOccupancy | null;
  peak: ReportPeak | null;
}

type Cell = string | number | null;

/**
 * Filas del informe en CSV. Incluye los KPIs y desgloses de ingresos siempre, y
 * canal, ocupación y horas punta cuando hay datos (estos dos últimos requieren
 * una sede concreta, por lo que pueden venir a null).
 */
export function reportCsvRows(b: ReportBundle): Cell[][] {
  const rows: Cell[][] = [];

  rows.push(["Periodo", `${b.from} a ${b.to}`]);
  rows.push(["Ingresos", b.revenue ? b.revenue.total_revenue : ""]);
  rows.push(["Tasa no-show", b.noShows && b.noShows.no_show_rate !== null ? b.noShows.no_show_rate : ""]);
  rows.push(["Retención", b.retention && b.retention.retention_rate !== null ? b.retention.retention_rate : ""]);
  rows.push(["Valoración media", b.ratings && b.ratings.count > 0 ? b.ratings.average : ""]);

  if (b.channel) {
    rows.push([]);
    rows.push(["Reservas por canal", "Citas"]);
    rows.push(["Web", b.channel.by_channel.web]);
    rows.push(["WhatsApp", b.channel.by_channel.whatsapp]);
    rows.push(["Manual", b.channel.by_channel.manual]);
  }

  rows.push([]);
  rows.push(["Ingresos por servicio", "Citas", "€"]);
  for (const r of b.revenue?.by_service ?? []) rows.push([r.service_name, r.appointments, r.revenue]);

  rows.push([]);
  rows.push(["Ingresos por profesional", "Citas", "€"]);
  for (const r of b.revenue?.by_staff ?? []) rows.push([r.staff_name ?? "Sin asignar", r.appointments, r.revenue]);

  if (b.occupancy) {
    rows.push([]);
    rows.push(["Ocupación (min reservados)", b.occupancy.booked_minutes]);
    rows.push(["Ocupación (min disponibles)", b.occupancy.capacity_minutes]);
    rows.push(["Ocupación (%)", b.occupancy.occupancy_rate !== null ? b.occupancy.occupancy_rate : ""]);
    if (b.occupancy.by_staff.length > 0) {
      rows.push([]);
      rows.push(["Ocupación por profesional", "Min reservados", "Citas"]);
      for (const s of b.occupancy.by_staff) {
        rows.push([s.staff_name ?? "Sin asignar", s.booked_minutes, s.appointments]);
      }
    }
  }

  if (b.peak && b.peak.slots.length > 0) {
    rows.push([]);
    rows.push(["Horas punta", "Hora", "Citas"]);
    for (const s of b.peak.slots) {
      rows.push([DAYS[s.weekday] ?? String(s.weekday), `${s.hour}h`, s.appointments]);
    }
  }

  return rows;
}
