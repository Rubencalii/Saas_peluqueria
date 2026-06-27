"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type Review } from "@/lib/admin";

function stars(n: number): string {
  return "★★★★★".slice(0, n) + "☆☆☆☆☆".slice(0, 5 - n);
}

export default function ValoracionesPage() {
  const [reviews, setReviews] = useState<Review[]>([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(20);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await admin.reviews(null, page);
      setReviews(r.reviews);
      setTotal(r.total);
      setPerPage(r.per_page);
    } catch {
      setReviews([]);
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  const totalPages = Math.max(1, Math.ceil(total / perPage));

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Valoraciones</h1>
        {total > 0 ? <span className="text-sm text-muted">{total} en total</span> : null}
      </header>

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-20 animate-pulse rounded-2xl bg-brand-soft/60" />
          ))}
        </div>
      ) : reviews.length === 0 ? (
        <p className="card p-6 text-center text-sm text-muted">Aún no hay valoraciones.</p>
      ) : (
        <>
          <ul className="space-y-2">
            {reviews.map((r) => (
              <li key={r.id} className="card p-4">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-lg tracking-widest text-[var(--brand)]" title={`${r.rating}/5`}>{stars(r.rating)}</span>
                  <span className="text-xs text-muted">{new Date(r.created_at).toLocaleDateString("es-ES")}</span>
                </div>
                {r.comment ? <p className="mt-1.5 text-sm">{r.comment}</p> : null}
                <p className="mt-1 text-xs text-muted">
                  {r.service_name}
                  {r.staff_name ? ` · ${r.staff_name}` : ""}
                  {r.customer_name ? ` · ${r.customer_name}` : ""}
                </p>
              </li>
            ))}
          </ul>

          {totalPages > 1 ? (
            <div className="flex items-center justify-between text-sm">
              <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="btn-ghost px-3 py-1.5 disabled:opacity-40">
                Anterior
              </button>
              <span className="text-muted">{page} / {totalPages}</span>
              <button disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)} className="btn-ghost px-3 py-1.5 disabled:opacity-40">
                Siguiente
              </button>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}
