import { describe, expect, it } from "vitest";
import { dateLocale, DICTS, LOCALES, normalizeLocale, t } from "./i18n";

describe("diccionarios", () => {
  it("todos los idiomas tienen exactamente las mismas claves", () => {
    const esKeys = Object.keys(DICTS.es).sort();
    for (const locale of LOCALES) {
      expect(Object.keys(DICTS[locale]).sort(), `claves de ${locale}`).toEqual(esKeys);
    }
  });

  it("ninguna traducción está vacía", () => {
    for (const locale of LOCALES) {
      for (const [key, value] of Object.entries(DICTS[locale])) {
        expect(value.trim(), `${locale}.${key}`).not.toBe("");
      }
    }
  });
});

describe("t", () => {
  it("traduce e interpola variables", () => {
    expect(t("es", "my.none", { name: "Marta" })).toBe("Hola Marta, no tienes próximas citas.");
    expect(t("en", "my.none", { name: "Marta" })).toBe("Hi Marta, you have no upcoming appointments.");
  });

  it("cae al castellano si falta la clave y devuelve la clave como último recurso", () => {
    expect(t("en", "clave.inventada")).toBe("clave.inventada");
  });
});

describe("normalizeLocale / dateLocale", () => {
  it("acepta ca y en; todo lo demás es es", () => {
    expect(normalizeLocale("ca")).toBe("ca");
    expect(normalizeLocale("en")).toBe("en");
    expect(normalizeLocale("fr")).toBe("es");
    expect(normalizeLocale(null)).toBe("es");
  });

  it("mapea al locale BCP-47 correcto", () => {
    expect(dateLocale("es")).toBe("es-ES");
    expect(dateLocale("ca")).toBe("ca-ES");
    expect(dateLocale("en")).toBe("en-GB");
  });
});
