import { describe, expect, it } from "vitest";
import { buildIcs } from "./ics";

describe("buildIcs", () => {
  const ics = buildIcs({
    title: "Corte mujer · Salón Centro",
    start: "2026-07-15T08:30:00.000Z",
    end: "2026-07-15T09:15:00.000Z",
    location: "Salón Centro",
    description: "Código: ABC123",
  });

  it("es un VCALENDAR/VEVENT válido", () => {
    expect(ics).toContain("BEGIN:VCALENDAR");
    expect(ics).toContain("BEGIN:VEVENT");
    expect(ics).toContain("END:VEVENT");
    expect(ics).toContain("END:VCALENDAR");
    expect(ics).toContain("UID:");
  });

  it("formatea las fechas en UTC compacto", () => {
    expect(ics).toContain("DTSTART:20260715T083000Z");
    expect(ics).toContain("DTEND:20260715T091500Z");
  });

  it("incluye los campos de texto", () => {
    expect(ics).toContain("SUMMARY:Corte mujer · Salón Centro");
    expect(ics).toContain("LOCATION:Salón Centro");
    expect(ics).toContain("DESCRIPTION:Código: ABC123");
  });

  it("escapa comas y puntos y coma", () => {
    const out = buildIcs({ title: "A, B; C", start: "2026-07-15T08:30:00Z", end: "2026-07-15T09:00:00Z" });
    expect(out).toContain("SUMMARY:A\\, B\\; C");
  });

  it("omite location/description si no se pasan", () => {
    const out = buildIcs({ title: "X", start: "2026-07-15T08:30:00Z", end: "2026-07-15T09:00:00Z" });
    expect(out).not.toContain("LOCATION:");
    expect(out).not.toContain("DESCRIPTION:");
  });
});
