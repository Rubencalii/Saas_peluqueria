"use client";

import { useCallback, useEffect, useState } from "react";
import { admin, type AdminLocation, type PanelTeamUser, type PanelUser } from "@/lib/admin";

const ROLES: Array<{ value: string; label: string }> = [
  { value: "recepcion", label: "Recepción" },
  { value: "profesional", label: "Profesional" },
  { value: "admin_sede", label: "Admin de sede" },
  { value: "admin_cadena", label: "Admin de cadena" },
];

const ROLE_LABEL = Object.fromEntries(ROLES.map((r) => [r.value, r.label]));

export default function UsuariosPage() {
  const [users, setUsers] = useState<PanelTeamUser[]>([]);
  const [locations, setLocations] = useState<AdminLocation[]>([]);
  const [me, setMe] = useState<PanelUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setUsers((await admin.users()).users);
    } catch {
      setForbidden(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
    admin.locations().then((r) => setLocations(r.locations)).catch(() => {});
    admin.me().then((r) => setMe(r.user)).catch(() => {});
  }, [load]);

  async function toggleActive(u: PanelTeamUser) {
    if (u.active && !confirm(`¿Desactivar a ${u.name}? Perderá el acceso al panel al instante.`)) return;
    setError(null);
    try {
      await admin.updateUser(u.id, { active: !u.active });
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo actualizar.");
    }
  }

  async function changeRole(u: PanelTeamUser, role: string) {
    setError(null);
    try {
      await admin.updateUser(u.id, {
        role,
        // Los roles de sede necesitan sede; propone la actual o la primera.
        location_id: role === "admin_cadena" ? null : (u.location?.id ?? locations[0]?.id ?? null),
      });
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo cambiar el rol.");
    }
  }

  async function changeLocation(u: PanelTeamUser, locationId: number) {
    setError(null);
    try {
      await admin.updateUser(u.id, { location_id: locationId });
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo cambiar la sede.");
    }
  }

  if (forbidden) {
    return (
      <p className="card p-6 text-center text-sm text-muted">
        Solo el administrador de la cadena puede gestionar los usuarios del panel.
      </p>
    );
  }

  return (
    <div className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Usuarios del panel</h1>
        <button onClick={() => setCreating(true)} className="btn-primary px-4 py-2.5">
          + Nuevo usuario
        </button>
      </header>
      <p className="text-sm text-muted">
        Quién puede entrar al panel y con qué permisos. Es independiente de «Personal»: un profesional
        puede trabajar sin acceso al panel.
      </p>

      {creating ? (
        <NewUser
          locations={locations}
          onClose={() => setCreating(false)}
          onCreated={async () => {
            setCreating(false);
            await load();
          }}
        />
      ) : null}

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-2xl bg-brand-soft/60" />
          ))}
        </div>
      ) : (
        <ul className="space-y-2">
          {users.map((u) => {
            const isMe = me?.id === u.id;
            return (
              <li key={u.id} className={"card flex flex-wrap items-center gap-3 p-4 " + (u.active ? "" : "opacity-60")}>
                <div className="min-w-0 flex-1">
                  <p className="font-medium">
                    {u.name}
                    {isMe ? <span className="ml-2 chip bg-brand-soft text-xs">tú</span> : null}
                    {!u.active ? <span className="ml-2 chip bg-zinc-200 text-xs text-zinc-600">inactivo</span> : null}
                  </p>
                  <p className="truncate text-sm text-muted">{u.email}</p>
                </div>

                {isMe ? (
                  <span className="chip bg-brand-soft">{ROLE_LABEL[u.role] ?? u.role}</span>
                ) : (
                  <div className="flex flex-wrap items-center gap-2">
                    <select
                      value={u.role}
                      onChange={(e) => changeRole(u, e.target.value)}
                      className="rounded-xl border border-border bg-card px-2 py-1.5 text-sm"
                    >
                      {ROLES.map((r) => (
                        <option key={r.value} value={r.value}>{r.label}</option>
                      ))}
                    </select>
                    {u.role !== "admin_cadena" ? (
                      <select
                        value={u.location?.id ?? ""}
                        onChange={(e) => changeLocation(u, Number(e.target.value))}
                        className="rounded-xl border border-border bg-card px-2 py-1.5 text-sm"
                      >
                        {locations.map((l) => (
                          <option key={l.id} value={l.id}>{l.name}</option>
                        ))}
                      </select>
                    ) : null}
                    <button
                      onClick={() => toggleActive(u)}
                      className={
                        "btn-ghost px-3 py-1.5 text-xs " +
                        (u.active ? "text-red-700 hover:border-red-300" : "")
                      }
                    >
                      {u.active ? "Desactivar" : "Reactivar"}
                    </button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function NewUser({
  locations,
  onClose,
  onCreated,
}: {
  locations: AdminLocation[];
  onClose: () => void;
  onCreated: () => void;
}) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState("recepcion");
  const [locationId, setLocationId] = useState<number | "">(locations[0]?.id ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (name.trim() === "" || email.trim() === "" || password.length < 8) {
      setError("Completa nombre, email y una contraseña de al menos 8 caracteres.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await admin.createUser({
        name: name.trim(),
        email: email.trim(),
        password,
        role,
        location_id: role === "admin_cadena" ? null : (locationId === "" ? null : Number(locationId)),
      });
      onCreated();
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo crear el usuario.");
      setSaving(false);
    }
  }

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Nuevo usuario</h2>
        <button onClick={onClose} className="text-muted hover:text-foreground">✕</button>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nombre" className="field" />
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" className="field" />
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="Contraseña (mín. 8)"
          autoComplete="new-password"
          className="field"
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm font-semibold">
          Rol
          <select value={role} onChange={(e) => setRole(e.target.value)} className="field">
            {ROLES.map((r) => (
              <option key={r.value} value={r.value}>{r.label}</option>
            ))}
          </select>
        </label>
        {role !== "admin_cadena" ? (
          <label className="block text-sm font-semibold">
            Sede
            <select
              value={locationId}
              onChange={(e) => setLocationId(e.target.value === "" ? "" : Number(e.target.value))}
              className="field"
            >
              {locations.map((l) => (
                <option key={l.id} value={l.id}>{l.name}</option>
              ))}
            </select>
          </label>
        ) : null}
      </div>

      {error ? <p className="text-sm text-red-700">{error}</p> : null}

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="btn-ghost">Cancelar</button>
        <button onClick={submit} disabled={saving} className="btn-primary px-5 py-2.5">
          {saving ? "Creando…" : "Crear usuario"}
        </button>
      </div>
    </div>
  );
}
