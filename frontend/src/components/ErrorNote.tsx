export function ErrorNote({ title, detail }: { title: string; detail?: string }) {
  return (
    <div className="rounded-[var(--radius-brand)] border border-border bg-card p-6 text-center">
      <p className="font-medium">{title}</p>
      {detail ? <p className="mt-1 text-sm text-muted">{detail}</p> : null}
    </div>
  );
}
