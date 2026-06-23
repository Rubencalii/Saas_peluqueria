import { notFound } from "next/navigation";
import { api } from "@/lib/api";
import { ErrorNote } from "@/components/ErrorNote";
import { BookingFlow } from "@/components/BookingFlow";

export default async function LocationPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;

  let locationName = "";
  let timeZone = "Europe/Madrid";
  let locationId = 0;
  let services;

  try {
    const [locations, servicesRes] = await Promise.all([api.locations(), api.services(slug)]);
    const loc = locations.find((l) => l.slug === slug);
    if (!loc) notFound();
    locationName = loc.name;
    timeZone = loc.timezone;
    locationId = servicesRes.location_id;
    services = servicesRes.services;
  } catch (e) {
    // services() devuelve 404 si la sede no existe en la cuenta.
    if (e instanceof Error && "status" in e && (e as { status: number }).status === 404) {
      notFound();
    }
    return (
      <ErrorNote
        title="No podemos cargar este salón ahora mismo."
        detail="Vuelve a intentarlo en unos minutos."
      />
    );
  }

  return (
    <div className="space-y-6">
      <section>
        <h1 className="text-2xl font-semibold tracking-tight">{locationName}</h1>
        <p className="mt-1 text-muted">Elige un servicio para ver los horarios disponibles.</p>
      </section>

      {services.length === 0 ? (
        <ErrorNote title="Este salón aún no tiene servicios reservables online." />
      ) : (
        <BookingFlow locationId={locationId} timeZone={timeZone} services={services} />
      )}
    </div>
  );
}
