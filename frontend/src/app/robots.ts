import type { MetadataRoute } from "next";

// Indexable: solo la parte pública de reserva. El panel, la consola y las
// páginas con token (valorar, restablecer) no pintan nada en un buscador.
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/panel", "/superadmin", "/valorar", "/restablecer-contrasena", "/recuperar-contrasena"],
    },
  };
}
