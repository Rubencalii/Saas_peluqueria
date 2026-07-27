import { describe, expect, it } from "vitest";
import { AREA_ROLES, canSee, type PanelArea, type PanelRole } from "./roles";

const ROLES: PanelRole[] = ["recepcion", "profesional", "admin_sede", "admin_cadena"];

describe("canSee", () => {
  it("sin rol (usuario aún sin cargar) no enseña nada", () => {
    for (const area of Object.keys(AREA_ROLES) as PanelArea[]) {
      expect(canSee(area, null)).toBe(false);
    }
  });

  it("admin_cadena entra en todas las áreas", () => {
    for (const area of Object.keys(AREA_ROLES) as PanelArea[]) {
      expect(canSee(area, "admin_cadena")).toBe(true);
    }
  });

  it("el profesional solo ve su día a día, no la configuración ni el negocio", () => {
    expect(canSee("agenda", "profesional")).toBe(true);
    expect(canSee("clientes", "profesional")).toBe(true);
    expect(canSee("seguridad", "profesional")).toBe(true);

    for (const area of ["servicios", "personal", "sedes", "informes", "cuenta", "usuarios", "caja"] as PanelArea[]) {
      expect(canSee(area, "profesional"), area).toBe(false);
    }
  });

  it("recepción gestiona el mostrador pero no la configuración", () => {
    for (const area of ["caja", "tarjetas", "espera", "recurrentes", "valoraciones", "bloqueos"] as PanelArea[]) {
      expect(canSee(area, "recepcion"), area).toBe(true);
    }
    for (const area of ["servicios", "personal", "informes", "bonos"] as PanelArea[]) {
      expect(canSee(area, "recepcion"), area).toBe(false);
    }
  });

  it("lo exclusivo de admin_cadena no lo ve admin_sede", () => {
    for (const area of ["usuarios", "auditoria", "cuenta", "apariencia"] as PanelArea[]) {
      expect(canSee(area, "admin_sede"), area).toBe(false);
    }
    expect(canSee("informes", "admin_sede")).toBe(true);
    expect(canSee("personal", "admin_sede")).toBe(true);
  });

  it("toda área declara al menos un rol y ninguno desconocido", () => {
    for (const [area, roles] of Object.entries(AREA_ROLES)) {
      expect(roles.length, area).toBeGreaterThan(0);
      for (const r of roles) expect(ROLES, area).toContain(r);
    }
  });
});
