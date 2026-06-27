import Link from "next/link";

export default function NotFound() {
  return (
    <div className="card mx-auto max-w-md p-8 text-center">
      <div className="text-5xl">🔍</div>
      <h1 className="mt-3 text-2xl font-bold tracking-tight">No encontramos esta página</h1>
      <p className="mt-1 text-muted">Puede que el enlace haya cambiado o el salón ya no esté disponible.</p>
      <Link href="/" className="btn-primary mt-5 inline-flex">Ver salones</Link>
    </div>
  );
}
