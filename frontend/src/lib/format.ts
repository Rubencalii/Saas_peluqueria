/** Formatea un instante ISO (UTC) en la zona de la sede. El locale es opcional
 * (por defecto español): la web pública pasa el del visitante; el panel no. */
export function formatDateTime(iso: string, timeZone: string, locale = "es-ES"): string {
  return new Intl.DateTimeFormat(locale, {
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
    timeZone,
  }).format(new Date(iso));
}

export function formatTime(iso: string, timeZone: string, locale = "es-ES"): string {
  return new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit",
    timeZone,
  }).format(new Date(iso));
}

export function formatDateLong(iso: string, timeZone: string, locale = "es-ES"): string {
  return new Intl.DateTimeFormat(locale, {
    weekday: "long",
    day: "numeric",
    month: "long",
    timeZone,
  }).format(new Date(iso));
}

export function formatPrice(value: number | null, locale = "es-ES"): string {
  if (value === null) return "";
  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency: "EUR",
    minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
  }).format(value);
}

/** YYYY-MM-DD de una fecha local (para el input date y la API). */
export function isoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}
