import type { MetadataRoute } from "next";

// PWA: el personal del salón usa el panel en el móvil entre cliente y
// cliente; "instalar como app" da icono, pantalla completa y arranque directo
// en la agenda. Next sirve esto en /manifest.webmanifest y lo enlaza solo.
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Panel del salón",
    short_name: "Salón",
    description: "Agenda, reservas y clientes de tu salón.",
    start_url: "/panel",
    display: "standalone",
    background_color: "#fbf8f4",
    theme_color: "#a96f43",
    icons: [
      { src: "/icon-192.png", sizes: "192x192", type: "image/png" },
      { src: "/icon-512.png", sizes: "512x512", type: "image/png" },
      { src: "/icon-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
