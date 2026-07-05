// i18n ligero de la WEB PÚBLICA (es/ca/en), sin dependencias. El panel se
// queda en castellano (herramienta interna). El idioma vive en la cookie
// `lang`; el servidor la lee para SSR y el selector la cambia + refresh.

export type Locale = "es" | "ca" | "en";

export const LOCALES: Locale[] = ["es", "ca", "en"];
export const DEFAULT_LOCALE: Locale = "es";

export function normalizeLocale(raw: string | null | undefined): Locale {
  return raw === "ca" || raw === "en" ? raw : DEFAULT_LOCALE;
}

/** Persiste el idioma elegido (cookie de un año; la lee el SSR). Solo cliente. */
export function persistLocale(locale: Locale): void {
  document.cookie = `lang=${locale}; path=/; max-age=31536000; samesite=lax`;
}

/** Locale BCP-47 para Intl (fechas/horas) coherente con el idioma elegido. */
export function dateLocale(locale: Locale): string {
  return locale === "ca" ? "ca-ES" : locale === "en" ? "en-GB" : "es-ES";
}

type Dict = Record<string, string>;

const ES: Dict = {
  // Cabecera y pie públicos
  "nav.myAppointment": "Mi cita",
  "footer.tagline": "Reservas online · Te confirmamos por WhatsApp 💬",

  // Portada
  "home.badge": "✨ Reserva en 1 minuto",
  "home.title1": "Tu próxima cita,",
  "home.title2": "sin llamadas ni esperas.",
  "home.subtitle":
    "Elige tu salón, el servicio y la hora que mejor te venga. Te confirmamos al instante por WhatsApp.",
  "home.chooseSalon": "Elige tu salón",
  "home.viewAndBook": "Ver servicios y reservar",
  "home.empty": "Aún no hay salones disponibles para reservar online.",
  "home.errorTitle": "No podemos cargar los salones ahora mismo.",
  "home.errorDetail": "Vuelve a intentarlo en unos minutos.",

  // Página del salón
  "salon.back": "← Todos los salones",
  "salon.chooseService": "Elige un servicio para ver los horarios disponibles.",
  "salon.noServices": "Este salón aún no tiene servicios reservables online.",
  "salon.errorTitle": "No podemos cargar este salón ahora mismo.",

  // Flujo de reserva
  "book.step.service": "Servicio",
  "book.step.datetime": "Día y hora",
  "book.step.customer": "Tus datos",
  "book.change": "Cambiar",
  "book.min": "min",
  "book.withWhom": "¿Con quién?",
  "book.noPreference": "Sin preferencia",
  "book.chooseDay": "Elige el día",
  "book.slots": "Horas disponibles",
  "book.noSlots": "No quedan huecos ese día. Prueba con otra fecha. 📅",
  "book.name": "Nombre y apellidos",
  "book.phone": "Teléfono",
  "book.emailOpt": "Email (opcional)",
  "book.waConsent": "Quiero recibir la confirmación y el recordatorio por WhatsApp 💬",
  "book.confirm": "Confirmar cita",
  "book.confirming": "Reservando…",
  "book.slotTaken": "Vaya, ese hueco se acaba de ocupar. Elige otro, por favor.",
  "book.error": "No se pudo completar la reserva.",
  "book.done.title": "¡Cita confirmada!",
  "book.done.codeIs": "Tu código de cita es",
  "book.done.codeHint": "Lo necesitarás para cambiarla o cancelarla en «Mi cita».",
  "book.done.addCalendar": "📅 Añadir al calendario",
  "book.done.viewMy": "Ver mi cita",
  "book.done.ok": "Hecho",

  // Mi cita (autogestión)
  "my.title": "Mi cita",
  "my.subtitle": "Consulta, cambia o cancela tu cita con tu teléfono y tu código.",
  "my.phone": "Teléfono",
  "my.code": "Código de cita",
  "my.codePlaceholder": "el que te dimos al reservar",
  "my.search": "Buscar mi cita",
  "my.searching": "Buscando…",
  "my.notFound": "No encontramos tu cita.",
  "my.none": "Hola {name}, no tienes próximas citas.",
  "my.reschedule": "Reprogramar",
  "my.cancel": "Cancelar",
  "my.cancelConfirm": "¿Seguro que quieres cancelar esta cita?",
  "my.cancelError": "No se pudo cancelar.",
  "my.newDay": "Nuevo día",
  "my.searchingSlots": "Buscando huecos…",
  "my.pickDay": "Elige un día para ver los huecos.",
  "my.noSlotsDay": "No quedan huecos ese día. Prueba otra fecha.",
  "my.slotTaken": "Ese hueco se acaba de ocupar. Elige otro.",
  "my.rescheduleError": "No se pudo reprogramar.",
  "my.back": "Volver",

  // Depósito (Stripe)
  "dep.paid": "Depósito pagado ✅",
  "dep.payTitle": "Paga tu depósito de {amount}",
  "dep.payBtn": "Pagar depósito de {amount}",
  "dep.preparing": "Preparando el pago…",
  "dep.unavailable": "El pago online no está disponible ahora mismo.",
  "dep.startError": "No se pudo iniciar el pago.",
  "dep.payNow": "Pagar ahora",
  "dep.processing": "Procesando…",
  "dep.payError": "El pago no se pudo completar.",
};

const CA: Dict = {
  "nav.myAppointment": "La meva cita",
  "footer.tagline": "Reserves en línia · Et confirmem per WhatsApp 💬",

  "home.badge": "✨ Reserva en 1 minut",
  "home.title1": "La teva pròxima cita,",
  "home.title2": "sense trucades ni esperes.",
  "home.subtitle":
    "Tria el teu saló, el servei i l'hora que et vagi millor. Et confirmem a l'instant per WhatsApp.",
  "home.chooseSalon": "Tria el teu saló",
  "home.viewAndBook": "Veure serveis i reservar",
  "home.empty": "Encara no hi ha salons disponibles per reservar en línia.",
  "home.errorTitle": "Ara mateix no podem carregar els salons.",
  "home.errorDetail": "Torna-ho a provar d'aquí a uns minuts.",

  "salon.back": "← Tots els salons",
  "salon.chooseService": "Tria un servei per veure els horaris disponibles.",
  "salon.noServices": "Aquest saló encara no té serveis reservables en línia.",
  "salon.errorTitle": "Ara mateix no podem carregar aquest saló.",

  "book.step.service": "Servei",
  "book.step.datetime": "Dia i hora",
  "book.step.customer": "Les teves dades",
  "book.change": "Canviar",
  "book.min": "min",
  "book.withWhom": "Amb qui?",
  "book.noPreference": "Sense preferència",
  "book.chooseDay": "Tria el dia",
  "book.slots": "Hores disponibles",
  "book.noSlots": "No queden hores aquest dia. Prova una altra data. 📅",
  "book.name": "Nom i cognoms",
  "book.phone": "Telèfon",
  "book.emailOpt": "Correu (opcional)",
  "book.waConsent": "Vull rebre la confirmació i el recordatori per WhatsApp 💬",
  "book.confirm": "Confirmar la cita",
  "book.confirming": "Reservant…",
  "book.slotTaken": "Vaja, aquesta hora s'acaba d'ocupar. Tria'n una altra, si us plau.",
  "book.error": "No s'ha pogut completar la reserva.",
  "book.done.title": "Cita confirmada!",
  "book.done.codeIs": "El teu codi de cita és",
  "book.done.codeHint": "El necessitaràs per canviar-la o cancel·lar-la a «La meva cita».",
  "book.done.addCalendar": "📅 Afegir al calendari",
  "book.done.viewMy": "Veure la meva cita",
  "book.done.ok": "Fet",

  "my.title": "La meva cita",
  "my.subtitle": "Consulta, canvia o cancel·la la teva cita amb el telèfon i el codi.",
  "my.phone": "Telèfon",
  "my.code": "Codi de cita",
  "my.codePlaceholder": "el que et vam donar en reservar",
  "my.search": "Buscar la meva cita",
  "my.searching": "Buscant…",
  "my.notFound": "No trobem la teva cita.",
  "my.none": "Hola {name}, no tens pròximes cites.",
  "my.reschedule": "Reprogramar",
  "my.cancel": "Cancel·lar",
  "my.cancelConfirm": "Segur que vols cancel·lar aquesta cita?",
  "my.cancelError": "No s'ha pogut cancel·lar.",
  "my.newDay": "Nou dia",
  "my.searchingSlots": "Buscant hores…",
  "my.pickDay": "Tria un dia per veure les hores.",
  "my.noSlotsDay": "No queden hores aquest dia. Prova una altra data.",
  "my.slotTaken": "Aquesta hora s'acaba d'ocupar. Tria'n una altra.",
  "my.rescheduleError": "No s'ha pogut reprogramar.",
  "my.back": "Tornar",

  "dep.paid": "Dipòsit pagat ✅",
  "dep.payTitle": "Paga el teu dipòsit de {amount}",
  "dep.payBtn": "Pagar dipòsit de {amount}",
  "dep.preparing": "Preparant el pagament…",
  "dep.unavailable": "El pagament en línia no està disponible ara mateix.",
  "dep.startError": "No s'ha pogut iniciar el pagament.",
  "dep.payNow": "Pagar ara",
  "dep.processing": "Processant…",
  "dep.payError": "El pagament no s'ha pogut completar.",
};

const EN: Dict = {
  "nav.myAppointment": "My appointment",
  "footer.tagline": "Online booking · We confirm via WhatsApp 💬",

  "home.badge": "✨ Book in 1 minute",
  "home.title1": "Your next appointment,",
  "home.title2": "no calls, no waiting.",
  "home.subtitle":
    "Pick your salon, the service and the time that suits you. We confirm instantly via WhatsApp.",
  "home.chooseSalon": "Pick your salon",
  "home.viewAndBook": "See services & book",
  "home.empty": "No salons available for online booking yet.",
  "home.errorTitle": "We can't load the salons right now.",
  "home.errorDetail": "Please try again in a few minutes.",

  "salon.back": "← All salons",
  "salon.chooseService": "Choose a service to see available times.",
  "salon.noServices": "This salon has no online-bookable services yet.",
  "salon.errorTitle": "We can't load this salon right now.",

  "book.step.service": "Service",
  "book.step.datetime": "Date & time",
  "book.step.customer": "Your details",
  "book.change": "Change",
  "book.min": "min",
  "book.withWhom": "With whom?",
  "book.noPreference": "No preference",
  "book.chooseDay": "Pick a day",
  "book.slots": "Available times",
  "book.noSlots": "No slots left that day. Try another date. 📅",
  "book.name": "Full name",
  "book.phone": "Phone",
  "book.emailOpt": "Email (optional)",
  "book.waConsent": "I want the confirmation and reminder via WhatsApp 💬",
  "book.confirm": "Confirm booking",
  "book.confirming": "Booking…",
  "book.slotTaken": "Oops, that slot was just taken. Please pick another one.",
  "book.error": "The booking could not be completed.",
  "book.done.title": "Appointment confirmed!",
  "book.done.codeIs": "Your appointment code is",
  "book.done.codeHint": "You'll need it to change or cancel it under “My appointment”.",
  "book.done.addCalendar": "📅 Add to calendar",
  "book.done.viewMy": "View my appointment",
  "book.done.ok": "Done",

  "my.title": "My appointment",
  "my.subtitle": "Check, change or cancel your appointment with your phone and code.",
  "my.phone": "Phone",
  "my.code": "Appointment code",
  "my.codePlaceholder": "the one we gave you when booking",
  "my.search": "Find my appointment",
  "my.searching": "Searching…",
  "my.notFound": "We couldn't find your appointment.",
  "my.none": "Hi {name}, you have no upcoming appointments.",
  "my.reschedule": "Reschedule",
  "my.cancel": "Cancel",
  "my.cancelConfirm": "Are you sure you want to cancel this appointment?",
  "my.cancelError": "It could not be cancelled.",
  "my.newDay": "New day",
  "my.searchingSlots": "Looking for slots…",
  "my.pickDay": "Pick a day to see available times.",
  "my.noSlotsDay": "No slots left that day. Try another date.",
  "my.slotTaken": "That slot was just taken. Pick another one.",
  "my.rescheduleError": "It could not be rescheduled.",
  "my.back": "Back",

  "dep.paid": "Deposit paid ✅",
  "dep.payTitle": "Pay your {amount} deposit",
  "dep.payBtn": "Pay {amount} deposit",
  "dep.preparing": "Preparing payment…",
  "dep.unavailable": "Online payment is not available right now.",
  "dep.startError": "The payment could not be started.",
  "dep.payNow": "Pay now",
  "dep.processing": "Processing…",
  "dep.payError": "The payment could not be completed.",
};

export const DICTS: Record<Locale, Dict> = { es: ES, ca: CA, en: EN };

/** Traduce una clave con interpolación {var}. Cae al castellano si falta. */
export function t(locale: Locale, key: string, vars?: Record<string, string>): string {
  let text = DICTS[locale][key] ?? ES[key] ?? key;
  if (vars) {
    for (const [k, v] of Object.entries(vars)) {
      text = text.replaceAll(`{${k}}`, v);
    }
  }

  return text;
}
