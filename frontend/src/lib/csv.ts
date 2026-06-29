// Generación y descarga de CSV (compatible con Excel: separador coma, comillas
// dobles escapadas, CRLF).

export function toCsv(headers: string[], rows: Array<Array<string | number | null>>): string {
  const esc = (v: string | number | null): string => {
    const s = v === null || v === undefined ? "" : String(v);
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
