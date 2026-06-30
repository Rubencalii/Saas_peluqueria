import type { AgendaAppointment } from "./admin";

/** Una cita de agenda con el nombre de su sede (para el dashboard multi-sede). */
export interface DashItem extends AgendaAppointment {
  locationName: string;
  timeZone: string;
}

// Solo las citas vivas cuentan como "próximas"; completadas/no-show/canceladas no.
const ACTIVE = new Set(["pendiente", "confirmada"]);

/**
 * Citas activas que aún no han empezado, ordenadas por hora de inicio y
 * limitadas a `limit`. Pura (sin estado ni reloj global) para poder testearla.
 */
export function upcomingAppointments(items: DashItem[], nowMs: number, limit = 6): DashItem[] {
  return items
    .filter((a) => ACTIVE.has(a.status) && Date.parse(a.start) >= nowMs)
    .sort((a, b) => Date.parse(a.start) - Date.parse(b.start))
    .slice(0, limit);
}
