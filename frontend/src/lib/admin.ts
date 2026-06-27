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
  is_superadmin: boolean;
}

export interface SaAccount {
  id: number;
  name: string;
  slug: string;
  status: string;
  created_at: string;
  plan_code: string | null;
  plan_name: string | null;
  subscription_status: string | null;
  counts: { locations: number; users: number; customers: number; appointments: number };
}

export interface SaStats {
  accounts: { total: number; active: number; trial: number; suspended: number; cancelled: number };
  appointments_total: number;
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

export interface LocationInput {
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

export interface ServiceLocationOffer {
  location_id: number;
  price_override: number | null;
}

export interface AdminService {
  id: number;
  name: string;
  duration_min: number;
  buffer_min: number;
  price: number | null;
  deposit_amount: number | null;
  description: string | null;
  active: boolean;
  segments: Array<{ position: number; minutes: number; busy: boolean }>;
  locations: ServiceLocationOffer[];
}

export interface ServiceInput {
  name: string;
  duration_min: number;
  buffer_min: number;
  price: number | null;
  deposit_amount: number | null;
  description: string | null;
  active: boolean;
  locations: Array<{ location_id: number; price_override: number | null }>;
}

export interface AdminStaff {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  active: boolean;
  location_ids: number[];
  service_ids: number[];
}

export interface StaffInput {
  name: string;
  email: string | null;
  phone: string | null;
  active: boolean;
  location_ids: number[];
  service_ids: number[];
}

export interface ScheduleEntry {
  id?: number;
  location_id: number;
  weekday: number; // 0 = lunes … 6 = domingo
  start_time: string; // HH:MM
  end_time: string; // HH:MM
}

export interface Conversation {
  wa_id: string;
  phone: string;
  customer_name: string | null;
  state: string;
  needs_human: boolean;
  location: { id: number; name: string } | null;
  updated_at: string;
}

export interface ConversationList {
  conversations: Conversation[];
  page: number;
  per_page: number;
  total: number;
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

export interface ReportScope {
  location_id: number | null;
  from: string;
  to: string;
}

export interface ReportRevenue {
  total_revenue: number;
  by_staff: Array<{ staff_id: number | null; staff_name: string | null; appointments: number; revenue: number }>;
  by_service: Array<{ service_id: number; service_name: string; appointments: number; revenue: number }>;
}

export interface ReportChannel {
  by_channel: { web: number; whatsapp: number; manual: number };
  total: number;
}

export interface ReportNoShows {
  no_shows: number;
  completed: number;
  no_show_rate: number | null;
}

export interface ReportRetention {
  customers: number;
  returning_customers: number;
  retention_rate: number | null;
}

export interface ReportRatings {
  count: number;
  average: number;
  by_staff: Array<{ staff_id: number | null; staff_name: string | null; count: number; average: number }>;
  by_service: Array<{ service_id: number; service_name: string; count: number; average: number }>;
}

export interface ReportOccupancy {
  booked_minutes: number;
  capacity_minutes: number;
  occupancy_rate: number | null;
  by_staff: Array<{ staff_id: number | null; staff_name: string | null; booked_minutes: number; appointments: number }>;
}

export interface ReportPeak {
  timezone: string;
  slots: Array<{ weekday: number; hour: number; appointments: number }>;
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

function reportQuery(s: ReportScope): string {
  const q = new URLSearchParams({ from: s.from, to: s.to });
  if (s.location_id) q.set("location_id", String(s.location_id));
  return q.toString();
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

  availability: (locationId: number, serviceId: number, date: string) =>
    adminFetch<{ date: string; slots: Array<{ start: string; staff_id: number }> }>(
      `/api/v1/admin/availability?location_id=${locationId}&service_id=${serviceId}&date=${date}`,
    ),

  createAppointment: (body: {
    location_id: number;
    service_id: number;
    staff_id: number | null;
    start: string;
    customer: { name: string; phone: string; email?: string | null };
  }) =>
    adminFetch<{ appointment_id: number }>("/api/v1/admin/appointments", {
      method: "POST",
      body: { ...body, channel: "manual" },
    }),

  setAppointmentStatus: (id: number, status: string) =>
    adminFetch<unknown>(`/api/v1/admin/appointments/${id}`, { method: "PATCH", body: { status } }),

  cancelAppointment: (id: number) =>
    adminFetch<unknown>(`/api/v1/admin/appointments/${id}`, { method: "DELETE" }),

  customers: (query: string, page: number) =>
    adminFetch<CustomerList>(
      `/api/v1/admin/customers?query=${encodeURIComponent(query)}&page=${page}&per_page=20`,
    ),

  customer: (id: number) => adminFetch<{ customer: CustomerDetail }>(`/api/v1/admin/customers/${id}`),

  services: () => adminFetch<{ services: AdminService[] }>("/api/v1/admin/services"),
  createService: (body: ServiceInput) =>
    adminFetch<{ id: number }>("/api/v1/admin/services", { method: "POST", body }),
  updateService: (id: number, body: Partial<ServiceInput>) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/services/${id}`, { method: "PATCH", body }),

  createLocation: (body: LocationInput) => adminFetch<{ id: number }>("/api/v1/admin/locations", { method: "POST", body }),
  updateLocation: (id: number, body: Partial<LocationInput>) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/locations/${id}`, { method: "PATCH", body }),

  staff: () => adminFetch<{ staff: AdminStaff[] }>("/api/v1/admin/staff"),
  createStaff: (body: StaffInput) => adminFetch<{ id: number }>("/api/v1/admin/staff", { method: "POST", body }),
  updateStaff: (id: number, body: Partial<StaffInput>) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/staff/${id}`, { method: "PATCH", body }),
  staffSchedule: (id: number) =>
    adminFetch<{ schedule: ScheduleEntry[] }>(`/api/v1/admin/staff/${id}/schedule`),
  setStaffSchedule: (id: number, locationId: number, entries: Array<{ weekday: number; start_time: string; end_time: string }>) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/staff/${id}/schedule`, {
      method: "POST",
      body: { location_id: locationId, entries },
    }),

  reportRevenue: (s: ReportScope) => adminFetch<ReportRevenue>(`/api/v1/admin/reports/revenue?${reportQuery(s)}`),
  reportChannel: (s: ReportScope) => adminFetch<ReportChannel>(`/api/v1/admin/reports/bookings-by-channel?${reportQuery(s)}`),
  reportNoShows: (s: ReportScope) => adminFetch<ReportNoShows>(`/api/v1/admin/reports/no-shows?${reportQuery(s)}`),
  reportRetention: (s: ReportScope) => adminFetch<ReportRetention>(`/api/v1/admin/reports/retention?${reportQuery(s)}`),
  reportRatings: (s: ReportScope) => adminFetch<ReportRatings>(`/api/v1/admin/reports/ratings?${reportQuery(s)}`),
  reportOccupancy: (s: ReportScope) => adminFetch<ReportOccupancy>(`/api/v1/admin/reports/occupancy?${reportQuery(s)}`),
  reportPeak: (s: ReportScope) => adminFetch<ReportPeak>(`/api/v1/admin/reports/peak-hours?${reportQuery(s)}`),

  conversations: (status: "pendiente" | "all", page: number) =>
    adminFetch<ConversationList>(`/api/v1/admin/conversations?status=${status}&page=${page}&per_page=20`),
  replyConversation: (waId: string, message: string, resolve: boolean) =>
    adminFetch<{ ok: boolean; resolved: boolean }>(`/api/v1/admin/conversations/${waId}/reply`, {
      method: "POST",
      body: { message, resolve },
    }),

  account: () => adminFetch<Account>("/api/v1/admin/account"),

  billingCheckout: (planCode: string) =>
    adminFetch<{ url: string }>("/api/v1/admin/billing/checkout", { method: "POST", body: { plan_code: planCode } }),

  billingPortal: () => adminFetch<{ url: string }>("/api/v1/admin/billing/portal", { method: "POST" }),

  branding: () => adminFetch<{ branding: Branding }>("/api/v1/admin/account/branding"),

  updateBranding: (input: Partial<Pick<Branding, "display_name" | "brand_color" | "accent_color" | "logo_url">>) =>
    adminFetch<{ branding: Branding }>("/api/v1/admin/account/branding", { method: "PATCH", body: input }),

  // Plataforma (super-admin)
  saStats: () => adminFetch<SaStats>("/api/v1/superadmin/stats"),
  saAccounts: () => adminFetch<{ accounts: SaAccount[] }>("/api/v1/superadmin/accounts"),
  saUpdateAccount: (id: number, body: { status?: string; plan_code?: string }) =>
    adminFetch<{ ok: boolean }>(`/api/v1/superadmin/accounts/${id}`, { method: "PATCH", body }),
};
