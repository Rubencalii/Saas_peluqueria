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

  it("escapa el punto y coma (separador en locales europeos)", () => {
    expect(toCsv(["x"], [["a;b"]])).toBe('x\r\n"a;b"');
  });

  it("conserva los números negativos como dato, no como fórmula", () => {
    expect(toCsv(["n"], [[-5]])).toBe("n\r\n-5");
  });

  it("neutraliza la inyección de fórmulas en texto (= + - @)", () => {
    expect(toCsv(["x"], [["=SUM(A1:A2)"]])).toBe("x\r\n'=SUM(A1:A2)");
    expect(toCsv(["x"], [["+1"]])).toBe("x\r\n'+1");
    expect(toCsv(["x"], [["-cmd"]])).toBe("x\r\n'-cmd");
    expect(toCsv(["x"], [["@foo"]])).toBe("x\r\n'@foo");
  });

  it("combina neutralización y comillas cuando además hay coma", () => {
    expect(toCsv(["x"], [["=A1,B1"]])).toBe('x\r\n"\'=A1,B1"');
  });

  it("no toca un = en medio del texto", () => {
    expect(toCsv(["x"], [["a=b"]])).toBe("x\r\na=b");
  });
});
