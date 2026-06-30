// Generación y descarga de CSV (compatible con Excel: separador coma, comillas
// dobles escapadas, CRLF).

export function toCsv(headers: string[], rows: Array<Array<string | number | null>>): string {
  const esc = (v: string | number | null): string => {
    if (v === null || v === undefined) return "";
    // Los números los generamos nosotros: son seguros (un -5 legítimo no se toca).
    const isText = typeof v !== "number";
    let s = String(v);
    // Mitiga la inyección de fórmulas (CSV injection): un valor de TEXTO que
    // empiece por = + - @ o por tab/retorno podría ejecutarse como fórmula al
    // abrir el CSV en Excel/Sheets. Lo neutralizamos con un apóstrofo inicial.
    if (isText && /^[=+\-@\t\r]/.test(s)) {
      s = "'" + s;
    }
    return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  return [headers, ...rows].map((r) => r.map(esc).join(",")).join("\r\n");
}

export function downloadCsv(filename: string, csv: string): void {
  // BOM para que Excel reconozca UTF-8 (acentos).
  const blob = new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
