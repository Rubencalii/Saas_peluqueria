"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, AdminApiError, type AuditList } from "@/lib/admin";

const METHOD_CLS: Record<string, string> = {
  GET: "bg-sky-100 text-sky-800",
  POST: "bg-emerald-100 text-emerald-800",
  PATCH: "bg-amber-100 text-amber-800",
  PUT: "bg-amber-100 text-amber-800",
  DELETE: "bg-red-100 text-red-700",
};

export default function AuditoriaPage() {
  const [page, setPage] = useState(1);
  const [list, setList] = useState<AuditList | null>(null);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setList(await admin.audit(page));
    } catch (e) {
      if (e instanceof AdminApiError && e.status === 403) setForbidden(true);
      setList(null);
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  const totalPages = list ? Math.max(1, Math.ceil(list.total / list.per_page)) : 1;

  if (forbidden) {
    return (
      <p className="card p-6 text-center text-sm text-muted">
        Solo el administrador de la cadena puede ver el registro de actividad.
      </p>
    );
  }

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Auditoría</h1>
        {list ? <span className="text-sm text-muted">{list.total} acciones</span> : null}
      </header>
      <p className="text-sm text-muted">
        Registro de actividad del panel: quién hizo qué y cuándo (escrituras y accesos relevantes).
      </p>

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="h-10 animate-pulse rounded-xl bg-brand-soft/60" />
          ))}
        </div>
      ) : !list || list.audit.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">Aún no hay actividad registrada.</p>
      ) : (
        <>
          <div className="card overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
                  <th className="px-4 py-2.5 font-semibold">Cuándo</th>
                  <th className="px-4 py-2.5 font-semibold">Quién</th>
                  <th className="px-4 py-2.5 font-semibold">Acción</th>
                  <th className="px-4 py-2.5 text-right font-semibold">Resultado</th>
                </tr>
              </thead>
              <tbody>
                {list.audit.map((a) => (
                  <tr key={a.id} className="border-b border-border/50 last:border-0">
                    <td className="whitespace-nowrap px-4 py-2 text-muted">
                      {new Date(a.created_at).toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" })}
                    </td>
                    <td className="max-w-40 truncate px-4 py-2">{a.user_email ?? "—"}</td>
                    <td className="px-4 py-2">
                      <span className={"chip mr-2 " + (METHOD_CLS[a.method] ?? "bg-zinc-200 text-zinc-600")}>
                        {a.method}
                      </span>
                      <code className="break-all text-xs">{a.path}</code>
                    </td>
                    <td className="px-4 py-2 text-right">
                      <span className={a.status_code < 400 ? "text-emerald-700" : "font-medium text-red-700"}>
                        {a.status_code}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 ? (
            <div className="flex items-center justify-between text-sm">
              <button
                disabled={page <= 1}
                onClick={() => setPage((p) => p - 1)}
                className="btn-ghost px-3 py-1.5 disabled:opacity-40"
              >
                Anterior
              </button>
              <span className="text-muted">{page} / {totalPages}</span>
              <button
                disabled={page >= totalPages}
                onClick={() => setPage((p) => p + 1)}
                className="btn-ghost px-3 py-1.5 disabled:opacity-40"
              >
                Siguiente
              </button>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}
