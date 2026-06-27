"use client";

import { useCallback, useEffect, useState } from "react";
import {
  admin,
  type AdminLocation,
  type ReportChannel,
  type ReportOccupancy,
  type ReportPeak,
  type ReportRatings,
  type ReportRetention,
  type ReportRevenue,
  type ReportNoShows,
} from "@/lib/admin";
import { formatPrice } from "@/lib/format";

const DAYS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];

function firstOfMonth(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-01`;
}
function todayStr(): string {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, "0")}-${String(n.getDate()).padStart(2, "0")}`;
}
function pct(v: number | null): string {
  return v === null ? "—" : `${(v * 100).toFixed(1)} %`;
}

export default function InformesPage() {
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [locationId, setLocationId] = useState<number | null>(null);
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(todayStr());

  const [revenue, setRevenue] = useState<ReportRevenue | null>(null);
  const [channel, setChannel] = useState<ReportChannel | null>(null);
  const [noShows, setNoShows] = useState<ReportNoShows | null>(null);
  const [retention, setRetention] = useState<ReportRetention | null>(null);
  const [ratings, setRatings] = useState<ReportRatings | null>(null);
  const [occupancy, setOccupancy] = useState<ReportOccupancy | null>(null);
  const [peak, setPeak] = useState<ReportPeak | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    admin.locations().then((r) => setLocations(r.locations)).catch(() => {});
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    const scope = { location_id: locationId, from, to };
    const [rev, ch, ns, ret, rat] = await Promise.all([
      admin.reportRevenue(scope).catch(() => null),
      admin.reportChannel(scope).catch(() => null),
      admin.reportNoShows(scope).catch(() => null),
      admin.reportRetention(scope).catch(() => null),
      admin.reportRatings(scope).catch(() => null),
    ]);
    setRevenue(rev);
    setChannel(ch);
    setNoShows(ns);
    setRetention(ret);
    setRatings(rat);
    // Ocupación y horas punta requieren una sede concreta.
    if (locationId) {
      const [occ, pk] = await Promise.all([
        admin.reportOccupancy(scope).catch(() => null),
        admin.reportPeak(scope).catch(() => null),
      ]);
      setOccupancy(occ);
      setPeak(pk);
    } else {
      setOccupancy(null);
      setPeak(null);
    }
    setLoading(false);
  }, [locationId, from, to]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="space-y-5">
      <h1 className="text-2xl font-bold tracking-tight">Informes</h1>

      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm font-semibold">
          Sede
          <select
            value={locationId ?? ""}
            onChange={(e) => setLocationId(e.target.value === "" ? null : Number(e.target.value))}
            className="field mt-1"
          >
            <option value="">Todas</option>
            {locations.map((l) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </select>
        </label>
        <label className="text-sm font-semibold">
          Desde
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="field mt-1" />
        </label>
        <label className="text-sm font-semibold">
          Hasta
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="field mt-1" />
        </label>
      </div>

      {loading ? (
        <div className="grid gap-3 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-24 animate-pulse rounded-2xl bg-brand-soft/50" />
          ))}
        </div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Kpi label="Ingresos" value={revenue ? formatPrice(revenue.total_revenue) : "—"} />
            <Kpi label="Tasa de no-show" value={noShows ? pct(noShows.no_show_rate) : "—"} />
            <Kpi label="Retención" value={retention ? pct(retention.retention_rate) : "—"} />
            <Kpi label="Valoración media" value={ratings && ratings.count > 0 ? `${ratings.average.toFixed(2)} ★` : "—"} />
          </div>

          {channel ? (
            <Section title="Reservas por canal">
              <div className="grid grid-cols-3 gap-3 text-center">
                <Mini label="Web" value={channel.by_channel.web} />
                <Mini label="WhatsApp" value={channel.by_channel.whatsapp} />
                <Mini label="Manual" value={channel.by_channel.manual} />
              </div>
            </Section>
          ) : null}

          {revenue ? (
            <Section title="Ingresos por servicio">
              {revenue.by_service.length === 0 ? (
                <Empty />
              ) : (
                <Table
                  rows={revenue.by_service.map((r) => [r.service_name, `${r.appointments} citas`, formatPrice(r.revenue)])}
                />
              )}
            </Section>
          ) : null}

          {revenue && revenue.by_staff.length > 0 ? (
            <Section title="Ingresos por profesional">
              <Table
                rows={revenue.by_staff.map((r) => [r.staff_name ?? "Sin asignar", `${r.appointments} citas`, formatPrice(r.revenue)])}
              />
            </Section>
          ) : null}

          {ratings && ratings.count > 0 ? (
            <Section title="Valoraciones por profesional">
              <Table
                rows={ratings.by_staff.map((r) => [r.staff_name ?? "Sin asignar", `${r.count} valoraciones`, `${r.average.toFixed(2)} ★`])}
              />
            </Section>
          ) : null}

          {locationId && occupancy ? (
            <Section title="Ocupación">
              <p className="mb-3 text-sm text-muted">
                {Math.round(occupancy.booked_minutes / 60)} h reservadas de {Math.round(occupancy.capacity_minutes / 60)} h
                disponibles · <span className="font-semibold text-foreground">{pct(occupancy.occupancy_rate)}</span>
              </p>
              <div className="h-2 overflow-hidden rounded-full bg-brand-soft">
                <div
                  className="h-full rounded-full"
                  style={{ width: `${Math.min(100, (occupancy.occupancy_rate ?? 0) * 100)}%`, background: "var(--brand)" }}
                />
              </div>
            </Section>
          ) : null}

          {locationId && peak ? (
            <Section title="Horas punta">
              {peak.slots.length === 0 ? <Empty /> : <PeakGrid peak={peak} />}
            </Section>
          ) : null}

          {!locationId ? (
            <p className="text-center text-xs text-muted">Elige una sede para ver ocupación y horas punta.</p>
          ) : null}
        </>
      )}
    </div>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="card p-4">
      <p className="text-2xl font-bold">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="card p-5">
      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">{title}</h2>
      {children}
    </section>
  );
}

function Mini({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl bg-brand-soft/50 py-3">
      <p className="text-xl font-bold">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}

function Table({ rows }: { rows: string[][] }) {
  return (
    <table className="w-full text-sm">
      <tbody>
        {rows.map((r, i) => (
          <tr key={i} className="border-b border-border/50 last:border-0">
            {r.map((c, j) => (
              <td key={j} className={"py-2 " + (j === 0 ? "font-medium" : "text-muted") + (j === r.length - 1 ? " text-right font-semibold text-foreground" : "")}>
                {c}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Empty() {
  return <p className="text-sm text-muted">Sin datos en este periodo.</p>;
}

function PeakGrid({ peak }: { peak: ReportPeak }) {
  const max = Math.max(1, ...peak.slots.map((s) => s.appointments));
  const hours = Array.from(new Set(peak.slots.map((s) => s.hour))).sort((a, b) => a - b);
  const byKey = new Map(peak.slots.map((s) => [`${s.weekday}-${s.hour}`, s.appointments]));
  return (
    <div className="overflow-x-auto">
      <table className="text-xs">
        <thead>
          <tr>
            <th className="p-1"></th>
            {hours.map((h) => (
              <th key={h} className="p-1 text-muted">{h}h</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {DAYS.map((d, wd) => (
            <tr key={wd}>
              <td className="p-1 pr-2 font-medium text-muted">{d}</td>
              {hours.map((h) => {
                const n = byKey.get(`${wd}-${h}`) ?? 0;
                return (
                  <td key={h} className="p-0.5">
                    <div
                      className="grid h-6 w-7 place-items-center rounded text-[10px]"
                      style={{
                        background: n === 0 ? "var(--brand-soft)" : "var(--brand)",
                        opacity: n === 0 ? 0.5 : 0.35 + 0.65 * (n / max),
                        color: n === 0 ? "var(--muted)" : "var(--brand-ink)",
                      }}
                      title={`${n} citas`}
                    >
                      {n || ""}
                    </div>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
