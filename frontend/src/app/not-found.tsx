import Link from "next/link";

// 404 raíz (rutas fuera del grupo público, p. ej. /panel/lo-que-sea).
export default function NotFound() {
  return (
    <div className="grid min-h-screen place-items-center px-4">
      <div className="fade-up text-center">
        <p className="text-6xl">💇</p>
        <h1 className="font-display mt-4 text-3xl font-bold tracking-tight">Página no encontrada</h1>
        <p className="mt-2 text-muted">La dirección no existe o ha cambiado de sitio.</p>
        <div className="mt-6 flex justify-center gap-2">
          <Link href="/" className="btn-ghost">Web de reservas</Link>
          <Link href="/panel" className="btn-primary">Ir al panel</Link>
        </div>
      </div>
    </div>
  );
}
