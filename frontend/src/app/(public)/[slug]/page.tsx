import type { Metadata } from "next";
import Link from "next/link";
import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { api } from "@/lib/api";
import { ErrorNote } from "@/components/ErrorNote";
import { BookingFlow } from "@/components/BookingFlow";
import { normalizeLocale, t } from "@/lib/i18n";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  try {
    const loc = (await api.locations()).find((l) => l.slug === slug);
    if (loc) {
      const title = `${loc.name} · Reservar cita`;
      const description = `Reserva tu cita en ${loc.name} online en menos de un minuto: elige servicio, día y hora, y te confirmamos por WhatsApp.`;

      return {
        title,
        description,
        // Para que compartir el enlace en WhatsApp/Instagram se vea bien.
        openGraph: {
          title,
          description,
          siteName: loc.name,
          type: "website",
          locale: "es_ES",
        },
      };
    }
  } catch {
    /* usa el título por defecto */
  }
  return { title: "Reservar cita" };
}

export default async function LocationPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const locale = normalizeLocale((await cookies()).get("lang")?.value);

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
    return <ErrorNote title={t(locale, "salon.errorTitle")} detail={t(locale, "home.errorDetail")} />;
  }

  return (
    <div className="space-y-6">
      <section>
        <Link href="/" className="text-sm font-medium text-muted transition hover:text-foreground">
          {t(locale, "salon.back")}
        </Link>
        <h1 className="mt-2 text-3xl font-bold tracking-tight">{locationName}</h1>
        <p className="mt-1 text-muted">{t(locale, "salon.chooseService")}</p>
      </section>

      {services.length === 0 ? (
        <ErrorNote title={t(locale, "salon.noServices")} />
      ) : (
        <BookingFlow locationId={locationId} locationName={locationName} timeZone={timeZone} services={services} />
      )}
    </div>
  );
}
