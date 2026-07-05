import type { Metadata } from "next";
import Link from "next/link";
import { cookies } from "next/headers";
import { api } from "@/lib/api";
import { ErrorNote } from "@/components/ErrorNote";
import { normalizeLocale, t } from "@/lib/i18n";
import type { Location } from "@/lib/types";

export const metadata: Metadata = {
  title: "Reserva tu cita",
  description: "Elige tu salón y reserva tu cita online en menos de un minuto.",
  openGraph: {
    title: "Reserva tu cita",
    description: "Elige tu salón y reserva tu cita online en menos de un minuto.",
    type: "website",
    locale: "es_ES",
  },
};

export default async function Home() {
  const locale = normalizeLocale((await cookies()).get("lang")?.value);
  let locations: Location[] = [];
  let failed = false;
  try {
    locations = await api.locations();
  } catch {
    failed = true;
  }

  return (
    <div className="space-y-8">
      <section className="fade-up relative overflow-hidden rounded-[var(--radius-brand)] border border-border bg-card p-8 shadow-[var(--shadow-soft)]">
        <div
          aria-hidden
          className="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full opacity-70 blur-2xl"
          style={{ background: "var(--brand-soft)" }}
        />
        <div
          aria-hidden
          className="pointer-events-none absolute -bottom-20 -left-12 h-44 w-44 rounded-full opacity-50 blur-3xl"
          style={{ background: "var(--accent-soft, var(--brand-soft))" }}
        />
        <p className="chip bg-brand-soft text-brand-strong">{t(locale, "home.badge")}</p>
        <h1 className="font-display mt-4 text-4xl font-bold leading-[1.08] tracking-tight sm:text-5xl">
          {t(locale, "home.title1")}
          <br className="hidden sm:block" />{" "}
          <span
            className="bg-clip-text text-transparent"
            style={{ backgroundImage: "linear-gradient(90deg, var(--brand), var(--accent))" }}
          >
            {t(locale, "home.title2")}
          </span>
        </h1>
        <p className="mt-4 max-w-md text-muted">{t(locale, "home.subtitle")}</p>
      </section>

      <section className="fade-up" style={{ animationDelay: "80ms" }}>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">
          {t(locale, "home.chooseSalon")}
        </h2>

        {failed ? (
          <ErrorNote title={t(locale, "home.errorTitle")} detail={t(locale, "home.errorDetail")} />
        ) : locations.length === 0 ? (
          <ErrorNote title={t(locale, "home.empty")} />
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2">
            {locations.map((loc) => (
              <li key={loc.id}>
                <Link href={`/${loc.slug}`} className="card-link group p-5">
                  <span className="flex items-center justify-between">
                    <span className="text-lg font-semibold">{loc.name}</span>
                    <span
                      className="grid h-9 w-9 place-items-center rounded-full text-brand-ink transition group-hover:translate-x-0.5"
                      style={{ background: "var(--brand)" }}
                    >
                      →
                    </span>
                  </span>
                  <span className="mt-1 block text-sm text-muted">{t(locale, "home.viewAndBook")}</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
