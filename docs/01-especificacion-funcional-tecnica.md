# 01 · Especificación Funcional y Técnica

> Documento maestro. Versión 0.2.

## 1. Resumen ejecutivo

Plataforma de reservas para una **cadena de peluquerías** con dos canales equivalentes de reserva:

1. **Página web** (self-service, responsive móvil + escritorio).
2. **WhatsApp**, en dos modos:
   - **Bot conversacional**: crear, consultar, modificar y cancelar reservas.
   - **Notificaciones automáticas**: confirmación, recordatorios y avisos de cambios.

Ambos canales se apoyan en un **backend único** que es la única fuente de verdad de disponibilidad, servicios, profesionales y citas. El sistema es **multi-sede** desde el diseño y permite **diseño propio por sede** (ver documento 08).

## 2. Objetivos y KPIs

**Objetivos**
- Reducir reservas manuales por teléfono.
- Bajar la tasa de ausencias (no-shows) con recordatorios.
- Centralizar la agenda de todas las sedes.
- Reserva 24/7 sin intervención humana.

**KPIs**
- % reservas por canal automático vs. teléfono.
- Tasa de no-shows antes/después de recordatorios.
- Tiempo medio de reserva por WhatsApp.
- % de cancelaciones/reprogramaciones self-service.
- Ocupación por sede y profesional.

## 3. Alcance

**Dentro**
- Reserva web responsive.
- Reserva por WhatsApp (bot).
- Notificaciones por WhatsApp.
- Gestión multi-sede y diseño propio por sede.
- Panel de administración.
- Gestión de servicios, profesionales, horarios y precios.
- Disponibilidad en tiempo real.
- Cancelación y reprogramación self-service.

**Fuera (candidatos a fases posteriores)**
- Pago online / depósito por reserva.
- Fidelización / puntos.
- App nativa (la web responsive cubre móvil).
- Integración con TPV/facturación.
- Campañas de marketing masivas.

## 4. Actores y roles

| Rol | Acciones principales |
|-----|----------------------|
| **Cliente** | Reservar, consultar, cancelar, reprogramar; recibe notificaciones |
| **Recepcionista** | Ver agenda, gestionar citas y clientes de su sede |
| **Profesional/estilista** | Ver su agenda, bloquear huecos, marcar ausencias |
| **Admin de sede** | Configurar servicios, horarios y profesionales de su sede |
| **Admin de cadena** | Gestión global, informes consolidados, usuarios, branding |

## 5. Requisitos funcionales (resumen)

> El detalle de disponibilidad está en el doc 02; los flujos en el 04; la API en el 06.

- **Reserva web**: elegir sede → servicio → profesional (o "sin preferencia") → fecha/hora entre huecos reales; confirmar con nombre + teléfono; bloqueo inmediato del hueco.
- **Reserva WhatsApp**: bot guía sede → servicio → profesional → franja; propone huecos con botones/listas; permite consultar/cancelar/reprogramar; deriva a humano si hace falta.
- **Notificaciones**: confirmación inmediata; recordatorios configurables (p. ej. 24 h y 2 h antes); avisos de cambios; seguimiento opcional post-cita.
- **Agenda**: horarios por profesional, descansos, días libres, bloqueos manuales; duración por servicio; control de concurrencia (sin dobles reservas).
- **Multi-sede**: alta/edición de sedes; servicios/precios comunes o por sede; profesional en una o varias sedes; cliente único en toda la cadena (clave: teléfono).
- **Panel**: agenda día/semana por sede y profesional; CRUD de citas; gestión de clientes; usuarios y permisos por rol; informes básicos.

## 6. Requisitos no funcionales

- **Disponibilidad** 24/7 para la reserva online.
- **Rendimiento**: disponibilidad y respuesta del bot en pocos segundos.
- **Escalabilidad**: multi-sede sin rehacer arquitectura.
- **Seguridad**: HTTPS, datos cifrados en reposo, acceso por rol.
- **Privacidad (RGPD/LOPDGDD)**: ver doc 09.
- **Consistencia**: fuente única de verdad; sin dobles reservas.
- **Trazabilidad**: registro de quién creó/modificó cada cita.
- **i18n / zona horaria**: español (y catalán/otros si aplica); Europe/Madrid; almacenamiento en UTC.

## 7. Arquitectura (visión)

*Web y WhatsApp son dos "caras" sobre el mismo backend.* Toda la lógica de negocio vive en el backend; los canales solo presentan y recogen datos.

```
        ┌─────────────┐        ┌──────────────────┐
        │   Web (SPA) │        │  WhatsApp (Meta) │
        └──────┬──────┘        └────────┬─────────┘
               │                        │ webhook
               ▼                        ▼
        ┌────────────────────────────────────────┐
        │            Backend / API (núcleo)        │
        │  Disponibilidad · Citas · Reglas · Roles │
        │            · Multi-tenant / temas        │
        └───────────────────┬──────────────────────┘
                            ▼
                    ┌───────────────┐     ┌────────────────┐
                    │  PostgreSQL   │     │ Jobs / colas    │
                    └───────────────┘     │ (recordatorios) │
                                          └────────────────┘
```

**Stack de referencia (orientativo, adaptable)**
- Frontend: React / Next.js.
- Backend: Node.js (NestJS) o Python (Django/FastAPI).
- BD: PostgreSQL.
- Colas/jobs: Redis + worker.
- WhatsApp: Cloud API de Meta (directa o vía BSP).
- Infra: contenedores en cloud; servidor en UTC.

## 8. WhatsApp — puntos críticos

- **API**: WhatsApp Business Platform (Cloud API), directa o vía **BSP**.
- **Verificación**: cuenta Business verificada + número dedicado (no el WhatsApp personal).
- **Plantillas (HSM)**: los mensajes que inicia el negocio (recordatorios) requieren **plantillas aprobadas por Meta** (ver doc 07).
- **Ventana de 24 h**: cuando el cliente escribe, se abre una ventana para responder con texto libre; fuera de ella, solo plantillas.
- **Mensajes interactivos**: usar **botones** y **listas** para elegir servicio/hueco con fiabilidad.
- **Coste**: según tipo de mensaje y país; **verificar tarifas vigentes** (el modelo de Meta cambia).
- **Bot**: recomendado **flujo estructurado por botones** en el MVP; IA conversacional como fase posterior.

## 9. Roadmap por fases

- **Fase 0 — Definición:** cerrar alcance, flujos, modelo de datos; decidir bot estructurado vs. IA.
- **Fase 1 — MVP:** web de reserva + backend con disponibilidad multi-sede + panel básico + confirmación por WhatsApp.
- **Fase 2 — WhatsApp completo:** bot de reservas + cancelación/reprogramación + recordatorios.
- **Fase 3 — Madurez:** informes/KPIs, optimización de no-shows, NLU en el bot, branding por sede pulido. Candidatos futuros: pago online, fidelización.

## 10. Riesgos y decisiones abiertas

| Riesgo / decisión | Impacto | Nota |
|-------------------|---------|------|
| Cambios de política/precio de Meta | Alto | Verificar antes de presupuestar |
| Aprobación de plantillas por Meta | Medio | Planificar con antelación |
| Bot estructurado vs. IA | Medio | Define complejidad y coste |
| Doble reserva por concurrencia | Alto | Bloqueo transaccional (doc 02) |
| Zonas horarias multi-sede | Medio | UTC + presentación local |
| Servicios con tiempos muertos | Medio | Decidir si entran en MVP (doc 02) |
| ¿Pago online en alcance? | Medio | Decidir pronto |
