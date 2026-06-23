// Tipos de la API pública (docs/openapi.yaml).

export interface Location {
  id: number;
  name: string;
  slug: string;
  timezone: string;
}

export interface Service {
  id: number;
  name: string;
  duration_min: number;
  buffer_min: number;
  price: number | null;
  description: string | null;
}

export interface ServicesResponse {
  location_id: number;
  services: Service[];
}

export interface Slot {
  start: string; // ISO 8601 (UTC)
  staff_id: number;
}

export interface Availability {
  date: string;
  slots: Slot[];
}

export interface AppointmentResult {
  appointment_id: number;
  status: string;
  staff_id: number;
  start: string;
  end: string;
  public_code: string;
  idempotent_replay?: boolean;
}

export interface CustomerInput {
  name: string;
  phone: string;
  email?: string | null;
}

export interface AppointmentInput {
  location_id: number;
  service_id: number;
  staff_id?: number | null;
  start: string;
  customer: CustomerInput;
  wa_consent?: boolean;
}

export interface LookupAppointment {
  appointment_id: number;
  status: string;
  start: string;
  end: string;
  service: { id: number; name: string };
  staff: { id: number; name: string } | null;
  location: { id: number; name: string; slug: string; timezone: string };
  public_code: string;
}

export interface LookupResult {
  customer: { name: string; phone: string };
  appointments: LookupAppointment[];
}

export interface ApiError {
  error: { code: string; message: string };
}
