export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="h-28 animate-pulse rounded-[var(--radius-brand)] bg-brand-soft/60" />
      <div className="grid gap-3 sm:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-24 animate-pulse rounded-[var(--radius-brand)] bg-brand-soft/50" />
        ))}
      </div>
    </div>
  );
}
