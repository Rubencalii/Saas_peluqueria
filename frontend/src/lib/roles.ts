// Qué áreas del panel puede usar cada rol. Espejo de lo que exige la API
// (assertRole en backend/src/Controller/Admin/*): si aquí se enseña algo que
// el backend rechaza, el usuario acaba en una página con 403.
//
// Fuente de verdad por área:
//  - agenda/clientes/whatsapp/seguridad: sin assertRole → cualquier rol.
//  - bloqueos, tarjetas, espera, recurrentes, valoraciones: + recepción.
//  - servicios, personal, sedes, informes, bonos: solo administradores.
//  - usuarios, auditoría, cuenta (Stripe) y apariencia (branding de la cuenta):
//    solo admin_cadena.

export type PanelRole = "recepcion" | "profesional" | "admin_sede" | "admin_cadena";

const TODOS: PanelRole[] = ["recepcion", "profesional", "admin_sede", "admin_cadena"];
const CON_RECEPCION: PanelRole[] = ["recepcion", "admin_sede", "admin_cadena"];
const ADMINS: PanelRole[] = ["admin_sede", "admin_cadena"];
const CADENA: PanelRole[] = ["admin_cadena"];

export const AREA_ROLES = {
  inicio: TODOS,
  agenda: TODOS,
  clientes: TODOS,
  whatsapp: TODOS,
  seguridad: TODOS,

  bloqueos: CON_RECEPCION,
  tarjetas: CON_RECEPCION,
  espera: CON_RECEPCION,
  recurrentes: CON_RECEPCION,
  valoraciones: CON_RECEPCION,

  servicios: ADMINS,
  personal: ADMINS,
  sedes: ADMINS,
  informes: ADMINS,
  bonos: ADMINS,

  usuarios: CADENA,
  auditoria: CADENA,
  cuenta: CADENA,
  apariencia: CADENA,
} as const satisfies Record<string, readonly PanelRole[]>;

export type PanelArea = keyof typeof AREA_ROLES;

/** ¿Este rol puede entrar en el área? Sin usuario cargado aún, no. */
export function canSee(area: PanelArea, role: PanelRole | null | undefined): boolean {
  if (!role) return false;
  return (AREA_ROLES[area] as readonly PanelRole[]).includes(role);
}
