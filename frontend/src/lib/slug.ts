// Identificadores de cuenta/sede (slugs): mismas reglas que el backend
// (SignupService::SLUG_RE): 3-40 caracteres, minúsculas/números/guiones,
// sin guion al principio ni al final.

export const SLUG_RE = /^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/;

/** Propuesta de slug a partir de un nombre ("Peluquería Lola" → "peluqueria-lola"). */
export function slugify(text: string): string {
  return text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "") // sin acentos (marcas diacríticas tras NFD)
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40)
    .replace(/-+$/, "");
}

export function isValidSlug(slug: string): boolean {
  return SLUG_RE.test(slug);
}
