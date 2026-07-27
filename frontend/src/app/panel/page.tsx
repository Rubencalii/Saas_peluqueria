"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { admin, type PanelUser, type ReportCommissions } from "@/lib/admin";
import { formatPrice, formatTime } from "@/lib/format";
import { aggregateOccupancy, upcomingAppointments, type DashItem } from "@/lib/dashboard";
import { canSee, type PanelArea } from "@/lib/roles";

function isoToday(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
}
function isoFirstOfMonth(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-01`;
}

const ACCIONES: Array<{ href: string; icon: string; title: string; desc: string; area: PanelArea }> = [
  { href: "/panel/agenda", icon: "📅", title: "Agenda", desc: "Ver el día y dar cita", area: "agenda" },
  { href: "/panel/clientes", icon: "👥", title: "Clientes", desc: "Buscar y ver fichas", area: "clientes" },
  { href: "/panel/servicios", icon: "✂️", title: "Servicios", desc: "Catálogo y precios", area: "servicios" },
  { href: "/panel/personal", icon: "🧑‍💼", title: "Personal", desc: "Equipo y horarios", area: "personal" },
  { href: "/panel/informes", icon: "📊", title: "Informes", desc: "Cómo va el negocio", area: "informes" },
  { href: "/panel/apariencia", icon: "🎨", title: "Apariencia", desc: "Tu marca y logo", area: "apariencia" },
  { href: "/panel/espera", icon: "⏳", title: "Lista de espera", desc: "Avisar al liberarse hueco", area: "espera" },
  { href: "/panel/seguridad", icon: "🔒", title: "Seguridad", desc: "Contraseña y 2FA", area: "seguridad" },
];

export default function PanelHome() {
  const [user, setUser] = useState<PanelUser | null>(null);
  const [today, setToday] = useState<number | null>(null);
  const [upcoming, setUpcoming] = useState<DashItem[]>([]);
  const [occupancy, setOccupancy] = useState<number | null>(null);
  const [pendingWa, setPendingWa] = useState<number | null>(null);
  const [revenue, setRevenue] = useState<number | null>(null);
  const [rating, setRating] = useState<{ avg: number; count: number } | null>(null);
  const [myCommission, setMyCommission] = useState<ReportCommissions | null>(null);

  useEffect(() => {
    // Se espera a saber el rol antes de pedir nada: cada bloque del panel de
    // inicio necesita permisos distintos y pedirlo todo dejaba media pantalla
    // con guiones (403) a quien no es administrador.
    admin
      .me()
      .then((r) => {
        setUser(r.user);
        void load(r.user);
      })
      .catch(() => setToday(null));

    async function load(u: PanelUser) {
      const day = isoToday();
      const scope = { location_id: null, from: isoFirstOfMonth(), to: day };

      // Agenda de hoy: une las de todas las sedes de la cuenta para el contador
      // y la lista de próximas citas.
      try {
        const { locations } = await admin.locations();
        const agendas = await Promise.all(
          locations.map((l) =>
            admin
              .agenda(l.id, day, "day")
              .then((a) => a.appointments.map((ap) => ({ ...ap, locationName: a.location.name, timeZone: a.location.timezone })))
              .catch(() => [] as DashItem[]),
          ),
        );
        const all = agendas.flat();
        setToday(all.length);
        setUpcoming(upcomingAppointments(all, Date.now()));

        if (canSee("informes", u.role)) {
          // Ocupación de hoy: minutos reservados / capacidad, sumando las sedes.
          const occ = await Promise.all(
            locations.map((l) =>
              admin
                .reportOccupancy({ location_id: l.id, from: day, to: day })
                .then((r) => ({ booked_minutes: r.booked_minutes, capacity_minutes: r.capacity_minutes }))
                .catch(() => ({ booked_minutes: 0, capacity_minutes: 0 })),
            ),
          );
          setOccupancy(aggregateOccupancy(occ));
        }
      } catch {
        setToday(null);
      }

      if (canSee("whatsapp", u.role)) {
        admin.conversations("pendiente", 1).then((r) => setPendingWa(r.total)).catch(() => setPendingWa(null));
      }
      if (canSee("informes", u.role)) {
        admin.reportRevenue(scope).then((r) => setRevenue(r.total_revenue)).catch(() => setRevenue(null));
      }
      if (canSee("valoraciones", u.role)) {
        admin.reportRatings(scope).then((r) => setRating({ avg: r.average, count: r.count })).catch(() => setRating(null));
      }
      // Un profesional solo puede consultar su propia liquidación: el backend
      // la acota a su ficha, así que aquí basta con pedirla.
      if (u.role === "profesional") {
        admin.reportCommissions(scope).then(setMyCommission).catch(() => setMyCommission(null));
      }
    }
  }, []);

  const role = user?.role;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">
          {user ? `Hola, ${user.name.split(" ")[0]} 👋` : "Inicio"}
        </h1>
        <p className="mt-1 text-muted">Un vistazo a tu salón.</p>
      </header>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <Kpi label="Citas hoy" value={today === null ? "—" : String(today)} href="/panel/agenda" />
        {canSee("informes", role) ? (
          <Kpi label="Ocupación hoy" value={occupancy === null ? "—" : `${Math.round(occupancy * 100)}%`} href="/panel/informes" />
        ) : null}
        {canSee("whatsapp", role) ? (
          <Kpi label="WhatsApp pendientes" value={pendingWa === null ? "—" : String(pendingWa)} href="/panel/whatsapp" highlight={(pendingWa ?? 0) > 0} />
        ) : null}
        {canSee("informes", role) ? (
          <Kpi label="Ingresos del mes" value={revenue === null ? "—" : formatPrice(revenue)} href="/panel/informes" />
        ) : null}
        {canSee("valoraciones", role) ? (
          <Kpi label="Valoración media" value={rating && rating.count > 0 ? `${rating.avg.toFixed(2)} ★` : "—"} href="/panel/valoraciones" />
        ) : null}
      </div>

      {myCommission ? (
        <section className="card p-5">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted">Tus comisiones este mes</h2>
          <p className="mt-2 text-3xl font-bold">{formatPrice(myCommission.total_commission)}</p>
          <p className="mt-1 text-sm text-muted">
            {myCommission.detail.reduce((n, d) => n + d.appointments, 0)} citas completadas ·{" "}
            {formatPrice(myCommission.total_revenue)} facturados
          </p>
          {myCommission.detail.length > 0 ? (
            <table className="mt-4 w-full text-sm">
              <tbody>
                {myCommission.detail.map((d) => (
                  <tr key={d.service_id} className="border-b border-border/50 last:border-0">
                    <td className="py-2 font-medium">{d.service_name}</td>
                    <td className="py-2 text-muted">
                      {d.appointments} citas · {d.rate_pct} %
                    </td>
                    <td className="py-2 text-right font-semibold">{formatPrice(d.commission)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="mt-3 text-sm text-muted">Aún no tienes citas completadas este mes.</p>
          )}
        </section>
      ) : null}

      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted">Próximas citas de hoy</h2>
          <Link href="/panel/agenda" className="text-sm font-medium text-[var(--brand)] hover:underline">Ver agenda →</Link>
        </div>
        {today === null ? (
          <p className="text-sm text-muted">No se pudo cargar la agenda.</p>
        ) : upcoming.length === 0 ? (
          <p className="card p-4 text-sm text-muted">No quedan citas por delante hoy. 🎉</p>
        ) : (
          <ul className="space-y-2">
            {upcoming.map((a) => {
              const showLoc = new Set(upcoming.map((u) => u.locationName)).size > 1;
              return (
                <li key={a.appointment_id} className="card flex items-center gap-3 p-3">
                  <span className="w-14 shrink-0 text-center font-semibold tabular-nums">{formatTime(a.start, a.timeZone)}</span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{a.customer?.name ?? "Sin cliente"}</p>
                    <p className="truncate text-sm text-muted">
                      {a.service.name}
                      {a.staff ? ` · ${a.staff.name}` : ""}
                      {showLoc ? ` · ${a.locationName}` : ""}
                    </p>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      <section>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Accesos rápidos</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          {ACCIONES.filter((a) => canSee(a.area, role)).map((a) => (
            <Action key={a.href} href={a.href} icon={a.icon} title={a.title} desc={a.desc} />
          ))}
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
