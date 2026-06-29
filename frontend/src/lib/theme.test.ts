import { describe, expect, it } from "vitest";
import { brandCss, brandName, brandVars, type Branding } from "./theme";

function b(over: Partial<Branding>): Branding {
  return { name: "Salón", display_name: null, brand_color: null, accent_color: null, logo_url: null, ...over };
}

describe("brandVars", () => {
  it("sin marca no devuelve variables (usa el tema por defecto)", () => {
    expect(brandVars(null)).toEqual({});
    expect(brandVars(b({}))).toEqual({});
  });

  it("deriva la paleta de un color de marca", () => {
    const v = brandVars(b({ brand_color: "#000000" })) as Record<string, string>;
    expect(v["--brand"]).toBe("#000000");
    // sobre negro, el texto de marca es claro
    expect(v["--brand-ink"]).toBe("#fffaf5");
    // hover = mezcla con negro (negro sigue negro)
    expect(v["--brand-strong"]).toBe("#000000");
    // --brand-soft lo deriva el CSS (color-mix), no brandVars
    expect(v["--brand-soft"]).toBeUndefined();
  });

  it("elige texto oscuro sobre marcas claras", () => {
    const v = brandVars(b({ brand_color: "#ffffff" })) as Record<string, string>;
    expect(v["--brand-ink"]).toBe("#241d17");
  });

  it("incluye el acento si se indica y lo normaliza", () => {
    const v = brandVars(b({ accent_color: "#10B981" })) as Record<string, string>;
    expect(v["--accent"]).toBe("#10b981");
    expect(v["--brand"]).toBeUndefined();
  });

  it("ignora colores inválidos", () => {
    expect(brandVars(b({ brand_color: "rojo" }))).toEqual({});
  });
});

describe("brandName", () => {
  it("prefiere display_name, luego name, luego el fallback", () => {
    expect(brandName(b({ display_name: "Aurora" }))).toBe("Aurora");
    expect(brandName(b({ display_name: null, name: "Salón" }))).toBe("Salón");
    expect(brandName(null, "Reservas")).toBe("Reservas");
  });
});

describe("brandCss", () => {
  it("envuelve las variables en :root{}", () => {
    expect(brandCss(b({ brand_color: "#000000" }))).toContain(":root{--brand:#000000;");
    expect(brandCss(b({}))).toBe("");
  });
});
