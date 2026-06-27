import { describe, expect, it } from "vitest";
import { formatPrice, formatTime, isoDate } from "./format";

describe("formatPrice", () => {
  it("formatea euros (entero sin decimales)", () => {
    const s = formatPrice(18);
    expect(s).toContain("18");
    expect(s).toContain("€");
    expect(s).not.toContain(",00");
  });

  it("mantiene decimales cuando los hay", () => {
    expect(formatPrice(12.5)).toContain("12,5");
  });

  it("devuelve cadena vacía si es null", () => {
    expect(formatPrice(null)).toBe("");
  });
});

describe("isoDate", () => {
  it("formatea AAAA-MM-DD con ceros", () => {
    expect(isoDate(new Date(2026, 0, 5))).toBe("2026-01-05");
    expect(isoDate(new Date(2026, 11, 31))).toBe("2026-12-31");
  });
});

describe("formatTime", () => {
  it("muestra la hora en la zona indicada, no en UTC", () => {
    // 08:30 UTC = 10:30 en Madrid (verano, +2)
    expect(formatTime("2026-07-15T08:30:00Z", "Europe/Madrid")).toBe("10:30");
  });
});
