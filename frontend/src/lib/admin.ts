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
  email_verified?: boolean;
  /** Ficha de profesional vinculada por email (para filtrar "mis citas"). */
  staff_id?: number | null;
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
  /** true si la suscripción la gestiona Stripe (cambiar plan a mano desincroniza el cobro). */
  stripe_managed: boolean;
  counts: { locations: number; users: number; customers: number; appointments: number };
}

export interface SaAccountDetail {
  account: { id: number; name: string; slug: string; status: string; created_at: string };
  subscription: {
    plan_code: string;
    plan_name: string | null;
    status: string;
    current_period_end: string | null;
    stripe_managed: boolean;
  } | null;
  admins: Array<{ id: number; name: string; email: string; active: boolean }>;
  locations: Array<{ id: number; name: string; slug: string; active: boolean }>;
  activity: { appointments_30d: number; last_appointment_at: string | null };
}

export interface SaStats {
  accounts: { total: number; active: number; trial: number; suspended: number; cancelled: number };
  appointments_total: number;
  signups_8w: Array<{ week: string; count: number }>;
}

export interface LoginResponse {
  token: string;
  expires_at: string;
  user: PanelUser;
}

export interface SignupInput {
  business_name: string;
  slug: string;
  admin: { name: string; email: string; password: string };
  location: { name: string; slug?: string; timezone?: string };
}

export interface SignupResponse extends LoginResponse {
  account: { id: number; slug: string };
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

export interface TimeBlock {
  id: number;
  staff: { id: number; name: string };
  location_id: number | null;
  start: string;
  end: string;
  reason: string | null;
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

export interface WaitlistItem {
  id: number;
  status: string;
  desired_date: string | null;
  created_at: string;
  notified_at: string | null;
  location: { id: number; name: string };
  service: { id: number; name: string };
  staff: { id: number; name: string } | null;
  customer: { name: string; phone: string };
}

export interface WaitlistList {
  waitlist: WaitlistItem[];
  page: number;
  per_page: number;
  total: number;
}

export interface Review {
  id: number;
  rating: number;
  comment: string | null;
  service_name: string;
  staff_name: string | null;
  customer_name: string | null;
  created_at: string;
}

export interface ReviewList {
  reviews: Review[];
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

export interface StaffNextSlot {
  staff_id: number;
  staff_name: string;
  next: { date: string; start: string } | null;
}

export interface ReportPeak {
  timezone: string;
  slots: Array<{ weekday: number; hour: number; appointments: number }>;
}

export interface MonthlyPoint {
  month: string; // YYYY-MM
  appointments: number;
  revenue: number;
}

export interface PanelTeamUser {
  id: number;
  name: string;
  email: string;
  role: PanelUser["role"];
  location: { id: number; name: string } | null;
  active: boolean;
}

export interface RecurringItem {
  id: number;
  weekday: number; // 0 = lunes … 6 = domingo
  time: string; // HH:MM (hora local de la sede)
  interval_weeks: number;
  last_generated_date: string | null;
  service_name: string;
  staff_name: string | null;
  customer: { name: string; phone: string };
}

export interface RecurringInput {
  location_id: number;
  service_id: number;
  staff_id: number | null;
  customer: { name: string; phone: string };
  weekday: number;
  time: string;
  interval_weeks: number;
}

export interface AuditEntry {
  id: number;
  user_id: number | null;
  user_email: string | null;
  method: string;
  path: string;
  status_code: number;
  created_at: string;
}

export interface AuditList {
  audit: AuditEntry[];
  page: number;
  per_page: number;
  total: number;
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

/**
 * Cuándo caduca un JWT del panel, en milisegundos de época (claim exp).
 * null si el token no es decodificable. Solo lee el payload: la validez
 * real la decide el backend.
 */
export function tokenExpiresAt(token: string): number | null {
  const parts = token.split(".");
  if (parts.length !== 3) return null;
  try {
    const payload: unknown = JSON.parse(atob(parts[1].replace(/-/g, "+").replace(/_/g, "/")));
    const exp = (payload as { exp?: unknown }).exp;
    return typeof exp === "number" ? exp * 1000 : null;
  } catch {
    return null;
  }
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
  login: (email: string, password: string, totpCode?: string) =>
    adminFetch<LoginResponse>("/api/v1/auth/login", {
      method: "POST",
      body: { email, password, ...(totpCode ? { totp_code: totpCode } : {}) },
    }),

  twoFactor: () => adminFetch<{ enabled: boolean }>("/api/v1/admin/2fa"),
  twoFactorSetup: () =>
    adminFetch<{ secret: string; otpauth_uri: string }>("/api/v1/admin/2fa/setup", { method: "POST" }),
  twoFactorEnable: (secret: string, code: string) =>
    adminFetch<{ ok: boolean; enabled: boolean }>("/api/v1/admin/2fa/enable", { method: "POST", body: { secret, code } }),
  twoFactorDisable: (code: string) =>
    adminFetch<{ ok: boolean; enabled: boolean }>("/api/v1/admin/2fa/disable", { method: "POST", body: { code } }),

  signup: (body: SignupInput) => adminFetch<SignupResponse>("/api/v1/signup", { method: "POST", body }),

  forgotPassword: (email: string) =>
    adminFetch<{ ok: boolean }>("/api/v1/auth/password/forgot", { method: "POST", body: { email } }),
  resetPassword: (token: string, password: string) =>
    adminFetch<{ ok: boolean }>("/api/v1/auth/password/reset", { method: "POST", body: { token, password } }),

  me: () => adminFetch<{ user: PanelUser }>("/api/v1/admin/me"),

  refresh: () => adminFetch<LoginResponse>("/api/v1/auth/refresh", { method: "POST" }),

  logout: () => adminFetch<{ ok: boolean }>("/api/v1/admin/auth/logout", { method: "POST" }),

  verifyEmail: (token: string) => adminFetch<{ ok: boolean }>("/api/v1/auth/verify-email", { method: "POST", body: { token } }),
  resendVerification: () => adminFetch<{ ok: boolean }>("/api/v1/admin/auth/resend-verification", { method: "POST" }),

  locations: () => adminFetch<{ locations: AdminLocation[] }>("/api/v1/admin/locations"),

  agenda: (locationId: number, date: string, view: "day" | "week") =>
    adminFetch<Agenda>(
      `/api/v1/admin/agenda?location_id=${locationId}&date=${date}&view=${view}`,
    ),

  availability: (locationId: number, serviceId: number, date: string) =>
    adminFetch<{ date: string; slots: Array<{ start: string; staff_id: number }> }>(
      `/api/v1/admin/availability?location_id=${locationId}&service_id=${serviceId}&date=${date}`,
    ),

  nextSlotsByStaff: (locationId: number, serviceId: number, date?: string) =>
    adminFetch<{ staff: StaffNextSlot[] }>(
      `/api/v1/admin/availability/next?location_id=${locationId}&service_id=${serviceId}` +
        (date ? `&date=${date}` : ""),
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

  customers: (query: string, page: number, consent: "" | "yes" | "no" = "") =>
    adminFetch<CustomerList>(
      `/api/v1/admin/customers?query=${encodeURIComponent(query)}&page=${page}&per_page=20` +
        (consent ? `&consent=${consent}` : ""),
    ),

  customer: (id: number) => adminFetch<{ customer: CustomerDetail }>(`/api/v1/admin/customers/${id}`),
  updateCustomer: (id: number, body: { name?: string; email?: string | null }) =>
    adminFetch<{ customer: CustomerDetail }>(`/api/v1/admin/customers/${id}`, { method: "PATCH", body }),
  exportCustomer: (id: number) => adminFetch<Record<string, unknown>>(`/api/v1/admin/customers/${id}/export`),
  anonymizeCustomer: (id: number) =>
    adminFetch<{ ok: boolean; anonymized: boolean }>(`/api/v1/admin/customers/${id}`, { method: "DELETE" }),

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
  timeBlocks: (from: string, to: string) =>
    adminFetch<{ time_blocks: TimeBlock[] }>(`/api/v1/admin/time-blocks?from=${from}&to=${to}`),
  createTimeBlock: (body: { staff_id: number; location_id: number | null; start: string; end: string; reason: string | null }) =>
    adminFetch<{ id: number }>("/api/v1/admin/time-blocks", { method: "POST", body }),
  deleteTimeBlock: (id: number) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/time-blocks/${id}`, { method: "DELETE" }),

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
  reportMonthly: (locationId: number | null) =>
    adminFetch<{ months: MonthlyPoint[] }>(
      `/api/v1/admin/reports/monthly${locationId ? `?location_id=${locationId}` : ""}`,
    ),

  conversations: (status: "pendiente" | "all", page: number) =>
    adminFetch<ConversationList>(`/api/v1/admin/conversations?status=${status}&page=${page}&per_page=20`),
  replyConversation: (waId: string, message: string, resolve: boolean) =>
    adminFetch<{ ok: boolean; resolved: boolean }>(`/api/v1/admin/conversations/${waId}/reply`, {
      method: "POST",
      body: { message, resolve },
    }),

  waitlist: (locationId: number | null, status: string, page: number) => {
    const q = new URLSearchParams({ status, page: String(page), per_page: "20" });
    if (locationId) q.set("location_id", String(locationId));
    return adminFetch<WaitlistList>(`/api/v1/admin/waitlist?${q.toString()}`);
  },
  cancelWaitlist: (id: number) => adminFetch<{ ok: boolean }>(`/api/v1/admin/waitlist/${id}`, { method: "DELETE" }),
  convertWaitlist: (id: number) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/waitlist/${id}/convert`, { method: "POST" }),

  reviews: (locationId: number | null, page: number) => {
    const q = new URLSearchParams({ page: String(page), per_page: "20" });
    if (locationId) q.set("location_id", String(locationId));
    return adminFetch<ReviewList>(`/api/v1/admin/reviews?${q.toString()}`);
  },

  account: () => adminFetch<Account>("/api/v1/admin/account"),

  audit: (page: number) => adminFetch<AuditList>(`/api/v1/admin/audit?page=${page}&per_page=25`),

  users: () => adminFetch<{ users: PanelTeamUser[] }>("/api/v1/admin/users"),
  createUser: (body: { name: string; email: string; password: string; role: string; location_id: number | null }) =>
    adminFetch<{ id: number }>("/api/v1/admin/users", { method: "POST", body }),
  updateUser: (id: number, body: Partial<{ name: string; role: string; location_id: number | null; active: boolean }>) =>
    adminFetch<{ user: PanelTeamUser }>(`/api/v1/admin/users/${id}`, { method: "PATCH", body }),

  recurring: (locationId: number) =>
    adminFetch<{ recurring: RecurringItem[] }>(`/api/v1/admin/recurring?location_id=${locationId}`),
  createRecurring: (body: RecurringInput) =>
    adminFetch<{ id: number }>("/api/v1/admin/recurring", { method: "POST", body }),
  deleteRecurring: (id: number) =>
    adminFetch<{ ok: boolean }>(`/api/v1/admin/recurring/${id}`, { method: "DELETE" }),

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
  saAccount: (id: number) => adminFetch<SaAccountDetail>(`/api/v1/superadmin/accounts/${id}`),
  saImpersonate: (id: number) =>
    adminFetch<LoginResponse & { account: { id: number; name: string } }>(
      `/api/v1/superadmin/accounts/${id}/impersonate`,
      { method: "POST" },
    ),
};
