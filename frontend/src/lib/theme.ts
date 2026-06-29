// Deriva las variables CSS del tema a partir de la marca de la cuenta
// (un color de marca + acento opcional). El resto de tonos se calculan.

import type { CSSProperties } from "react";

export interface Branding {
  name: string;
  display_name: string | null;
  brand_color: string | null;
  accent_color: string | null;
  logo_url: string | null;
}

interface Rgb {
  r: number;
  g: number;
  b: number;
}

function parseHex(hex: string): Rgb | null {
  const m = /^#?([0-9a-fA-F]{6})$/.exec(hex.trim());
  if (!m) return null;
  const n = parseInt(m[1], 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function toHex({ r, g, b }: Rgb): string {
  const h = (v: number) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, "0");
  return `#${h(r)}${h(g)}${h(b)}`;
}

function mix(a: Rgb, b: Rgb, t: number): Rgb {
  return { r: a.r + (b.r - a.r) * t, g: a.g + (b.g - a.g) * t, b: a.b + (b.b - a.b) * t };
}

const WHITE: Rgb = { r: 255, g: 255, b: 255 };
const BLACK: Rgb = { r: 0, g: 0, b: 0 };

/** Texto legible (claro u oscuro) sobre un color de fondo. */
function inkOn(c: Rgb): string {
  const lum = (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255;
  return lum > 0.6 ? "#241d17" : "#fffaf5";
}

/**
 * Variables CSS a inyectar para una marca. Devuelve solo las que aplican; si no
 * hay color de marca, no sobreescribe el tema por defecto.
 */
export function brandVars(branding: Branding | null | undefined): CSSProperties {
  const vars: Record<string, string> = {};
  if (!branding) return vars as CSSProperties;

  const brand = branding.brand_color ? parseHex(branding.brand_color) : null;
  if (brand) {
    vars["--brand"] = toHex(brand);
    vars["--brand-strong"] = toHex(mix(brand, BLACK, 0.16));
    vars["--brand-ink"] = inkOn(brand);
    vars["--ring"] = toHex(mix(brand, WHITE, 0.4));
    // --brand-soft NO se fija aquí: se deriva en CSS con color-mix(marca, fondo)
    // para que se adapte al color de marca Y al tema (claro/oscuro).
  }

  const accent = branding.accent_color ? parseHex(branding.accent_color) : null;
  if (accent) {
    vars["--accent"] = toHex(accent);
  }

  return vars as CSSProperties;
}

/** CSS `:root { … }` con las variables de la marca, para inyectar en un <style>. */
export function brandCss(branding: Branding | null | undefined): string {
  const vars = brandVars(branding) as Record<string, string>;
  const body = Object.entries(vars)
    .map(([k, v]) => `${k}:${v};`)
    .join("");
  return body ? `:root{${body}}` : "";
}

/** Nombre visible de la marca (display_name o el nombre de la cuenta). */
export function brandName(branding: Branding | null | undefined, fallback = "Reservas"): string {
  return branding?.display_name?.trim() || branding?.name?.trim() || fallback;
}
