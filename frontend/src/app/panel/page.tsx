"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { admin, type PanelUser } from "@/lib/admin";
import { formatPrice } from "@/lib/format";

function isoToday(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
}
function isoFirstOfMonth(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-01`;
}

export default function PanelHome() {
  const [user, setUser] = useState<PanelUser | null>(null);
  const [today, setToday] = useState<number | null>(null);
  const [pendingWa, setPendingWa] = useState<number | null>(null);
  const [revenue, setRevenue] = useState<number | null>(null);
  const [rating, setRating] = useState<{ avg: number; count: number } | null>(null);

  useEffect(() => {
    admin.me().then((r) => setUser(r.user)).catch(() => {});

    // Citas de hoy: suma de las agendas de todas las sedes de la cuenta.
    (async () => {
      try {
        const { locations } = await admin.locations();
        const day = isoToday();
        const counts = await Promise.all(
          locations.map((l) => admin.agenda(l.id, day, "day").then((a) => a.appointments.length).catch(() => 0)),
        );
        setToday(counts.reduce((a, b) => a + b, 0));
      } catch {
        setToday(null);
      }
    })();

    admin.conversations("pendiente", 1).then((r) => setPendingWa(r.total)).catch(() => setPendingWa(null));

    const scope = { location_id: null, from: isoFirstOfMonth(), to: isoToday() };
    admin.reportRevenue(scope).then((r) => setRevenue(r.total_revenue)).catch(() => setRevenue(null));
    admin.reportRatings(scope).then((r) => setRating({ avg: r.average, count: r.count })).catch(() => setRating(null));
  }, []);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">
          {user ? `Hola, ${user.name.split(" ")[0]} 👋` : "Inicio"}
        </h1>
        <p className="mt-1 text-muted">Un vistazo a tu salón.</p>
      </header>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Kpi label="Citas hoy" value={today === null ? "—" : String(today)} href="/panel/agenda" />
        <Kpi label="WhatsApp pendientes" value={pendingWa === null ? "—" : String(pendingWa)} href="/panel/whatsapp" highlight={(pendingWa ?? 0) > 0} />
        <Kpi label="Ingresos del mes" value={revenue === null ? "—" : formatPrice(revenue)} href="/panel/informes" />
        <Kpi label="Valoración media" value={rating && rating.count > 0 ? `${rating.avg.toFixed(2)} ★` : "—"} href="/panel/valoraciones" />
      </div>

      <section>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Accesos rápidos</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <Action href="/panel/agenda" icon="📅" title="Agenda" desc="Ver el día y dar cita" />
          <Action href="/panel/clientes" icon="👥" title="Clientes" desc="Buscar y ver fichas" />
          <Action href="/panel/servicios" icon="✂️" title="Servicios" desc="Catálogo y precios" />
          <Action href="/panel/personal" icon="🧑‍💼" title="Personal" desc="Equipo y horarios" />
          <Action href="/panel/informes" icon="📊" title="Informes" desc="Cómo va el negocio" />
          <Action href="/panel/apariencia" icon="🎨" title="Apariencia" desc="Tu marca y logo" />
        </div>
      </section>
    </div>
  );
}

function Kpi({ label, value, href, highlight }: { label: string; value: string; href: string; highlight?: boolean }) {
  return (
    <Link href={href} className={"card p-4 transition hover:border-[var(--ring)] " + (highlight ? "border-[var(--brand)]" : "")}>
      <p className="text-2xl font-bold">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </Link>
  );
}

function Action({ href, icon, title, desc }: { href: string; icon: string; title: string; desc: string }) {
  return (
    <Link href={href} className="card-link p-4">
      <div className="flex items-center gap-3">
        <span className="grid h-10 w-10 place-items-center rounded-full bg-brand-soft text-lg">{icon}</span>
        <div>
          <p className="font-semibold">{title}</p>
          <p className="text-sm text-muted">{desc}</p>
        </div>
      </div>
    </Link>
  );
}
