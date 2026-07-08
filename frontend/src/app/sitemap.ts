import type { MetadataRoute } from "next";
import { headers } from "next/headers";
import { api } from "@/lib/api";

// Sitemap por host (multi-tenant): la portada y las sedes reservables de LA
// CUENTA de este dominio. Se genera por petición (host → cuenta), igual que
// el resto de la web pública.
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const h = await headers();
  const proto = h.get("x-forwarded-proto") ?? "https";
  const host = h.get("host") ?? "localhost:3000";
  const base = `${proto}://${host}`;

  const entries: MetadataRoute.Sitemap = [
    { url: `${base}/`, changeFrequency: "weekly", priority: 1 },
    { url: `${base}/mi-cita`, changeFrequency: "monthly", priority: 0.3 },
  ];

  try {
    for (const loc of await api.locations()) {
      entries.push({ url: `${base}/${loc.slug}`, changeFrequency: "weekly", priority: 0.8 });
    }
  } catch {
    // Sin backend a mano: el sitemap sale igualmente con las rutas fijas.
  }

  return entries;
}
