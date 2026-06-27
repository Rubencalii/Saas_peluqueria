export default function Loading() {
  return (
    <div className="space-y-4">
      <div className="h-8 w-40 animate-pulse rounded-lg bg-brand-soft/60" />
      <div className="h-64 animate-pulse rounded-[var(--radius-brand)] bg-brand-soft/50" />
    </div>
  );
}
