import Link from "next/link";
import { api } from "@/lib/api";
import { ErrorNote } from "@/components/ErrorNote";
import type { Location } from "@/lib/types";

export default async function Home() {
  let locations: Location[] = [];
  let failed = false;
  try {
    locations = await api.locations();
  } catch {
    failed = true;
  }

  return (
    <div className="space-y-6">
      <section className="rounded-[var(--radius-brand)] bg-brand-soft p-6">
        <h1 className="text-2xl font-semibold tracking-tight">Reserva tu cita</h1>
        <p className="mt-1 text-muted">Elige tu salón y en menos de un minuto tienes tu hora.</p>
      </section>

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
              <Link
                href={`/${loc.slug}`}
                className="block h-full rounded-[var(--radius-brand)] border border-border bg-card p-5 transition-colors hover:border-brand"
              >
                <span className="text-lg font-medium">{loc.name}</span>
                <span className="mt-1 block text-sm text-muted">Ver servicios y reservar →</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
