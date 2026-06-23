export function ErrorNote({ title, detail }: { title: string; detail?: string }) {
  return (
    <div className="card p-6 text-center">
      <div className="mx-auto mb-2 grid h-10 w-10 place-items-center rounded-full bg-brand-soft text-lg">
        💤
      </div>
      <p className="font-medium">{title}</p>
      {detail ? <p className="mt-1 text-sm text-muted">{detail}</p> : null}
    </div>
  );
}
