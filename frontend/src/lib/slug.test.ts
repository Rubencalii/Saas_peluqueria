import { describe, expect, it } from "vitest";
import { isValidSlug, slugify } from "./slug";

describe("slugify", () => {
  it("convierte nombres con acentos y espacios", () => {
    expect(slugify("Peluquería Lola")).toBe("peluqueria-lola");
    expect(slugify("Salón Ñandú & Cía.")).toBe("salon-nandu-cia");
  });

  it("colapsa separadores y recorta guiones en los extremos", () => {
    expect(slugify("  --Corte  y   Color--  ")).toBe("corte-y-color");
  });

  it("limita a 40 caracteres sin dejar guion final", () => {
    const out = slugify("a".repeat(39) + " bcd");
    expect(out.length).toBeLessThanOrEqual(40);
    expect(out.endsWith("-")).toBe(false);
  });

  it("conserva números", () => {
    expect(slugify("Sede 21 Madrid")).toBe("sede-21-madrid");
  });
});

describe("isValidSlug", () => {
  it("acepta los slugs que genera slugify", () => {
    expect(isValidSlug(slugify("Peluquería Lola"))).toBe(true);
    expect(isValidSlug("salon-2")).toBe(true);
  });

  it("rechaza mayúsculas, cortos y guiones en extremos", () => {
    expect(isValidSlug("ab")).toBe(false);
    expect(isValidSlug("Mi-Salon")).toBe(false);
    expect(isValidSlug("-lola")).toBe(false);
    expect(isValidSlug("lola-")).toBe(false);
  });
});
