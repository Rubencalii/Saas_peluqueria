import type { Metadata } from "next";
import Link from "next/link";
import { api } from "@/lib/api";
import { ErrorNote } from "@/components/ErrorNote";
import type { Location } from "@/lib/types";

export const metadata: Metadata = {
  title: "Reserva tu cita",
  description: "Elige tu salón y reserva tu cita online en menos de un minuto.",
};

export default async function Home() {
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
        <p className="chip bg-brand-soft text-brand-strong">✨ Reserva en 1 minuto</p>
        <h1 className="font-display mt-4 text-4xl font-bold leading-[1.08] tracking-tight sm:text-5xl">
          Tu próxima cita,
          <br className="hidden sm:block" />{" "}
          <span
            className="bg-clip-text text-transparent"
            style={{ backgroundImage: "linear-gradient(90deg, var(--brand), var(--accent))" }}
          >
            sin llamadas ni esperas.
          </span>
        </h1>
        <p className="mt-4 max-w-md text-muted">
          Elige tu salón, el servicio y la hora que mejor te venga. Te confirmamos al instante por
          WhatsApp.
        </p>
      </section>

      <section className="fade-up" style={{ animationDelay: "80ms" }}>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">
          Elige tu salón
        </h2>

        {failed ? (
          <ErrorNote
            title="No podemos cargar los salones ahora mismo."
            detail="Vuelve a intentarlo en unos minutos."
          />
        ) : locations.length === 0 ? (
          <ErrorNote title="Aún no hay salones disponibles para reservar online." />
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
                  <span className="mt-1 block text-sm text-muted">Ver servicios y reservar</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
