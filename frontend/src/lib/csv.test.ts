import { describe, expect, it } from "vitest";
import { toCsv } from "./csv";

describe("toCsv", () => {
  it("une cabeceras y filas con CRLF", () => {
    const csv = toCsv(["A", "B"], [["1", "2"], ["3", "4"]]);
    expect(csv).toBe("A,B\r\n1,2\r\n3,4");
  });

  it("escapa comas, comillas y saltos de línea", () => {
    const csv = toCsv(["x"], [['a,b'], ['di "hola"'], ["l1\nl2"]]);
    expect(csv).toContain('"a,b"');
    expect(csv).toContain('"di ""hola"""');
    expect(csv).toContain('"l1\nl2"');
  });

  it("trata null y números", () => {
    expect(toCsv(["a", "b"], [[null, 12]])).toBe("a,b\r\n,12");
  });
});
