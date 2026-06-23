import type {
  Availability,
  AppointmentInput,
  AppointmentResult,
  Location,
  LookupResult,
  ServicesResponse,
} from "./types";

/**
 * Base de la API. En el servidor (SSR) usamos la URL absoluta del backend; en el
 * navegador usamos rutas relativas que Next reescribe al backend (next.config),
 * así evitamos CORS en desarrollo.
 */
function base(): string {
  if (typeof window === "undefined") {
    return process.env.API_BASE ?? "http://localhost:8000";
  }
  return "";
}

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message);
  }
}

interface RequestOptions {
  method?: string;
  body?: unknown;
  /** Revalidación de la caché de fetch (SSR). 0 = sin caché. */
  revalidate?: number;
}

async function request<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const res = await fetch(base() + path, {
    method: opts.method ?? "GET",
    headers: opts.body ? { "Content-Type": "application/json" } : undefined,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    next: opts.revalidate !== undefined ? { revalidate: opts.revalidate } : undefined,
  });

  const text = await res.text();
  const data = text ? JSON.parse(text) : null;

  if (!res.ok) {
    const err = data?.error;
    throw new ApiError(
      err?.code ?? "ERROR",
      err?.message ?? "Algo ha ido mal. Inténtalo de nuevo.",
      res.status,
    );
  }
  return data as T;
}

export const api = {
  locations: () => request<Location[]>("/api/v1/locations", { revalidate: 60 }),

  services: (slug: string) =>
    request<ServicesResponse>(`/api/v1/locations/${encodeURIComponent(slug)}/services`, {
      revalidate: 60,
    }),

  availability: (params: { location_id: number; service_id: number; date: string; staff_id?: number | null }) => {
    const q = new URLSearchParams({
      location_id: String(params.location_id),
      service_id: String(params.service_id),
      date: params.date,
    });
    if (params.staff_id) q.set("staff_id", String(params.staff_id));
    return request<Availability>(`/api/v1/availability?${q.toString()}`, { revalidate: 0 });
  },

  createAppointment: (body: AppointmentInput, idempotencyKey?: string) =>
    fetch(base() + "/api/v1/appointments", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {}),
      },
      body: JSON.stringify(body),
    }).then(async (res) => {
      const data = await res.json().catch(() => null);
      if (!res.ok) {
        throw new ApiError(data?.error?.code ?? "ERROR", data?.error?.message ?? "No se pudo crear la reserva.", res.status);
      }
      return data as AppointmentResult;
    }),

  lookup: (phone: string, code: string) => {
    const q = new URLSearchParams({ phone, code });
    return request<LookupResult>(`/api/v1/appointments/lookup?${q.toString()}`, { revalidate: 0 });
  },

  reschedule: (id: number, start: string, code: string) =>
    request<AppointmentResult>(`/api/v1/appointments/${id}/reschedule`, {
      method: "PATCH",
      body: { start, code },
    }),

  cancel: (id: number, code: string) =>
    request<{ ok: boolean }>(`/api/v1/appointments/${id}?code=${encodeURIComponent(code)}`, {
      method: "DELETE",
    }),
};
