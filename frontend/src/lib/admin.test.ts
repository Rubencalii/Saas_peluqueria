import { describe, expect, it } from "vitest";
import { tokenExpiresAt } from "./admin";

/** JWT falso (sin firma válida) con el payload dado — solo para decodificar. */
function fakeJwt(payload: Record<string, unknown>): string {
  const b64 = (o: unknown) => btoa(JSON.stringify(o)).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  return `${b64({ alg: "HS256", typ: "JWT" })}.${b64(payload)}.firma`;
}

describe("tokenExpiresAt", () => {
  it("devuelve exp en milisegundos", () => {
    expect(tokenExpiresAt(fakeJwt({ exp: 1_783_215_516 }))).toBe(1_783_215_516_000);
  });

  it("null si el token no tiene tres partes o no es JSON", () => {
    expect(tokenExpiresAt("no-es-un-jwt")).toBeNull();
    expect(tokenExpiresAt("a.b")).toBeNull();
    expect(tokenExpiresAt("a.%%%.c")).toBeNull();
  });

  it("null si el payload no trae exp numérico", () => {
    expect(tokenExpiresAt(fakeJwt({ sub: 1 }))).toBeNull();
    expect(tokenExpiresAt(fakeJwt({ exp: "mañana" }))).toBeNull();
  });
});
