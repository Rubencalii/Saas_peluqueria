"use client";

// Valoración post-cita: el cliente llega desde el WhatsApp de agradecimiento
// (/valorar?cita=ID&code=CODIGO). Con nota alta, invita a dejar la reseña
// también en Google si la sede tiene el enlace configurado.
import { useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { formatDateLong } from "@/lib/format";
import { useLang } from "@/components/LangProvider";

type Ctx = {
  service_name: string;
  location_name: string;
  start: string;
  timezone: string;
  status: string;
  already_reviewed: boolean;
};

export default function ValorarPage() {
  const { t, intl } = useLang();
  const [params, setParams] = useState<{ id: number; code: string } | null>(null);
  const [ctx, setCtx] = useState<Ctx | null>(null);
  const [state, setState] = useState<"loading" | "form" | "notFound" | "notCompleted" | "already" | "done">("loading");
  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState("");
  const [googleUrl, setGoogleUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const q = new URLSearchParams(window.location.search);
    const id = Number(q.get("cita") ?? 0);
    const code = q.get("code") ?? "";
    if (!id || code === "") {
      setState("notFound");
      return;
    }
    setParams({ id, code });
    api
      .reviewContext(id, code)
      .then((r) => {
        setCtx(r.appointment);
        if (r.appointment.already_reviewed) setState("already");
        else if (r.appointment.status !== "completada") setState("notCompleted");
        else setState("form");
      })
      .catch(() => setState("notFound"));
  }, []);

  async function submit() {
    if (!params || rating < 1) return;
    setBusy(true);
    setError(null);
    try {
      const r = await api.submitReview(params.id, params.code, rating, comment.trim() || null);
      setGoogleUrl(r.google_review_url);
      setState("done");
    } catch (e) {
      setError(e instanceof Error ? e.message : t("rate.error"));
    } finally {
      setBusy(false);
    }
  }

  if (state === "loading") {
    return <div className="skeleton mx-auto h-64 max-w-md" />;
  }

  if (state === "notFound" || state === "notCompleted" || state === "already") {
    return (
      <div className="card fade-up mx-auto max-w-md p-8 text-center">
        <div className="text-4xl">{state === "already" ? "💛" : "🔎"}</div>
        <p className="mt-3 font-medium">
          {state === "already" ? t("rate.already") : state === "notCompleted" ? t("rate.notCompleted") : t("rate.notFound")}
        </p>
        <Link href="/" className="btn-ghost mt-5 inline-flex">{t("rate.back")}</Link>
      </div>
    );
  }

  if (state === "done") {
    return (
      <div className="card fade-up mx-auto max-w-md p-8 text-center">
        <div className="pop-in mx-auto grid h-16 w-16 place-items-center rounded-full text-3xl text-white" style={{ background: "var(--accent)" }}>
          ✓
        </div>
        <h1 className="font-display mt-4 text-2xl font-bold">{t("rate.thanks")}</h1>
        <p className="mt-1 text-muted">{t("rate.thanksSub")}</p>

        {googleUrl ? (
          <div className="mt-6 rounded-2xl bg-brand-soft p-4">
            <p className="text-sm">{t("rate.google")}</p>
            <a href={googleUrl} target="_blank" rel="noopener noreferrer" className="btn-primary mt-3 inline-flex">
              {t("rate.googleBtn")}
            </a>
          </div>
        ) : null}

        <Link href="/" className="btn-ghost mt-5 inline-flex">{t("rate.back")}</Link>
      </div>
    );
  }

  return (
    <div className="card fade-up mx-auto max-w-md space-y-5 p-6">
      <div className="text-center">
        <h1 className="font-display text-2xl font-bold">{t("rate.title")}</h1>
        {ctx ? (
          <>
            <p className="mt-1 text-sm text-muted">{t("rate.subtitle", { loc: ctx.location_name })}</p>
            <p className="mt-2 text-sm">
              {ctx.service_name} · <span className="capitalize">{formatDateLong(ctx.start, ctx.timezone, intl)}</span>
            </p>
          </>
        ) : null}
      </div>

      <div className="flex justify-center gap-2">
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            key={n}
            onClick={() => setRating(n)}
            aria-label={`${n}/5`}
            className={
              "text-4xl transition hover:scale-110 " + (n <= rating ? "grayscale-0" : "opacity-30 grayscale")
            }
          >
            ⭐
          </button>
        ))}
      </div>

      <textarea
        value={comment}
        onChange={(e) => setComment(e.target.value)}
        rows={3}
        maxLength={500}
        placeholder={t("rate.commentLabel")}
        className="field resize-y"
      />

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <button onClick={submit} disabled={busy || rating < 1} className="btn-primary w-full">
        {busy ? t("rate.sending") : t("rate.submit")}
      </button>
    </div>
  );
}
