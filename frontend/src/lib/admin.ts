// Cliente del panel de administración: auth JWT + llamadas autenticadas.
// El token vive en localStorage; cada petición lleva Authorization: Bearer.

import type { Branding } from "./theme";

const TOKEN_KEY = "panel_token";

export interface PanelUser {
  id: number;
  name: string;
  email: string;
  role: "recepcion" | "profesional" | "admin_sede" | "admin_cadena";
  location_id: number | null;
  account_id: number;
}

export interface LoginResponse {
  token: string;
  expires_at: string;
  user: PanelUser;
}

export interface AdminLocation {
  id: number;
  name: string;
  slug: string;
  address: string | null;
  phone: string | null;
  timezone: string;
  active: boolean;
}

export interface AgendaAppointment {
  appointment_id: number;
  status: string;
  channel: string;
  start: string;
  end: string;
  notes: string | null;
  public_code: string | null;
  service: { id: number; name: string; duration_min: number };
  staff: { id: number; name: string } | null;
  customer: { id: number; name: string; phone: string } | null;
}

export interface Agenda {
  location: { id: number; name: string; slug: string; timezone: string };
  view: "day" | "week";
  from: string;
  to: string;
  appointments: AgendaAppointment[];
}

export interface CustomerListItem {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  wa_consent: boolean;
  created_at: string;
}

export interface CustomerList {
  customers: CustomerListItem[];
  page: number;
  per_page: number;
  total: number;
}

export interface CustomerDetail extends CustomerListItem {
  consent_at: string | null;
  loyalty: {
    points: number;
    history: Array<{ points: number; reason: string; appointment_id: number | null; created_at: string }>;
  };
  appointments: Array<{
    appointment_id: number;
    status: string;
    start: string;
    end: string;
    service_name: string;
    location_name: string;
    staff_name: string | null;
  }>;
}

export interface Account {
  account: { id: number; name: string; slug: string; status: string; created_at: string };
  subscription: {
    plan_code: string;
    plan_name: string;
    status: string;
    current_period_end: string | null;
    limits: { max_locations: number | null; max_staff: number | null; max_appointments_month: number | null };
  } | null;
}

export class AdminApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message);
  }
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string): void {
  window.localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  window.localStorage.removeItem(TOKEN_KEY);
}

async function adminFetch<T>(path: string, opts: { method?: string; body?: unknown } = {}): Promise<T> {
  const token = getToken();
  const res = await fetch(path, {
    method: opts.method ?? "GET",
    headers: {
      ...(opts.body ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });

  const text = await res.text();
  const data = text ? JSON.parse(text) : null;

  if (res.status === 401 && typeof window !== "undefined" && !path.endsWith("/auth/login")) {
    clearToken();
    if (!window.location.pathname.startsWith("/panel/login")) {
      window.location.href = "/panel/login";
    }
  }
  if (!res.ok) {
    throw new AdminApiError(data?.error?.code ?? "ERROR", data?.error?.message ?? "Algo ha ido mal.", res.status);
  }
  return data as T;
}

export const admin = {
  login: (email: string, password: string) =>
    adminFetch<LoginResponse>("/api/v1/auth/login", { method: "POST", body: { email, password } }),

  me: () => adminFetch<{ user: PanelUser }>("/api/v1/admin/me"),

  logout: () => adminFetch<{ ok: boolean }>("/api/v1/admin/auth/logout", { method: "POST" }),

  locations: () => adminFetch<{ locations: AdminLocation[] }>("/api/v1/admin/locations"),

  agenda: (locationId: number, date: string, view: "day" | "week") =>
    adminFetch<Agenda>(
      `/api/v1/admin/agenda?location_id=${locationId}&date=${date}&view=${view}`,
    ),

  setAppointmentStatus: (id: number, status: string) =>
    adminFetch<unknown>(`/api/v1/admin/appointments/${id}`, { method: "PATCH", body: { status } }),

  cancelAppointment: (id: number) =>
    adminFetch<unknown>(`/api/v1/admin/appointments/${id}`, { method: "DELETE" }),

  customers: (query: string, page: number) =>
    adminFetch<CustomerList>(
      `/api/v1/admin/customers?query=${encodeURIComponent(query)}&page=${page}&per_page=20`,
    ),

  customer: (id: number) => adminFetch<{ customer: CustomerDetail }>(`/api/v1/admin/customers/${id}`),

  account: () => adminFetch<Account>("/api/v1/admin/account"),

  billingCheckout: (planCode: string) =>
    adminFetch<{ url: string }>("/api/v1/admin/billing/checkout", { method: "POST", body: { plan_code: planCode } }),

  billingPortal: () => adminFetch<{ url: string }>("/api/v1/admin/billing/portal", { method: "POST" }),

  branding: () => adminFetch<{ branding: Branding }>("/api/v1/admin/account/branding"),

  updateBranding: (input: Partial<Pick<Branding, "display_name" | "brand_color" | "accent_color" | "logo_url">>) =>
    adminFetch<{ branding: Branding }>("/api/v1/admin/account/branding", { method: "PATCH", body: input }),
};
