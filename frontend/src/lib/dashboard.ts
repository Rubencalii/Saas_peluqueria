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

/**
 * Próxima cita activa (la más cercana en el futuro) de una lista cualquiera de
 * citas con `status` y `start`. `null` si no hay ninguna por venir. Pura.
 */
export function nextAppointment<T extends { status: string; start: string }>(
  items: readonly T[],
  nowMs: number,
): T | null {
  const upcoming = items
    .filter((a) => ACTIVE.has(a.status) && Date.parse(a.start) >= nowMs)
    .sort((a, b) => Date.parse(a.start) - Date.parse(b.start));
  return upcoming[0] ?? null;
}

/**
 * Ocupación combinada de varias sedes: minutos reservados sobre la capacidad
 * total. `null` si no hay capacidad (ninguna sede tiene horario ese día).
 */
export function aggregateOccupancy(
  parts: ReadonlyArray<{ booked_minutes: number; capacity_minutes: number }>,
): number | null {
  const capacity = parts.reduce((s, p) => s + p.capacity_minutes, 0);
  if (capacity <= 0) return null;
  const booked = parts.reduce((s, p) => s + p.booked_minutes, 0);
  return booked / capacity;
}
