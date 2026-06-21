# Documentación — Sistema de Reservas para Peluquería (Cadena Multi-sede)

Conjunto de documentos de partida para construir una plataforma de reservas con:
- **Página web** responsive (móvil + escritorio)
- **WhatsApp** como bot conversacional de reservas + notificaciones automáticas
- **Multi-sede** (cadena) con **backend único** y **diseño propio por sede** (white-label)

> Estado: borrador inicial / punto de partida · Versión 0.2
> Pensado para un equipo/desarrollador técnico.

---

## Índice de documentos

| # | Documento | Para qué sirve |
|---|-----------|----------------|
| 01 | **Especificación funcional y técnica** | Visión general, alcance, actores, requisitos, arquitectura |
| 02 | **Lógica de disponibilidad** | Cómo se calculan huecos, anti-doble-reserva, servicios con tiempos muertos |
| 03 | **Guiones del bot de WhatsApp** | Diálogos paso a paso, botones, casos límite |
| 04 | **Wireframes y flujos (web + panel)** | Pantallas, navegación y flujos de usuario |
| 05 | **Modelo de datos y esquema SQL** | Tablas, relaciones, diagrama ER |
| 06 | **Especificación de la API** | Endpoints compartidos por web y bot |
| 07 | **Plantillas de mensajes WhatsApp** | Textos listos para aprobación de Meta |
| 08 | **Multi-tenant y diseño propio (white-label)** | Cómo compartir backend con marca distinta por sede |
| 09 | **RGPD y privacidad** | Cumplimiento legal de datos personales |
| 10 | **Plan de pruebas y criterios de aceptación** | Cómo validar que todo funciona |
| 11 | **Despliegue y DevOps** | Entornos, backups, CI/CD, monitorización |
| 12 | **Manual del personal** | Guía de uso del panel para recepción y profesionales |
| 13 | **Funcionalidades adicionales (backlog)** | Mejoras futuras y su priorización |

---

## Cómo leer esta documentación

- Si vas a **definir el proyecto**: empieza por **01**, luego **02** y **08**.
- Si vas a **desarrollar el backend**: **02**, **05**, **06**.
- Si vas a **desarrollar la web/panel**: **04**, **06**, **08**.
- Si vas a **montar WhatsApp**: **03**, **07** y la sección de WhatsApp del **01**.
- Si te encargas de **legal/operaciones**: **09**, **12**.

## Decisiones aún abiertas (cerrar pronto)

1. Bot **estructurado por botones** vs. **conversacional con IA** (recomendado: estructurado en el MVP).
2. ¿Entra el **pago online** en el alcance inicial? (por defecto, fuera).
3. ¿Cada sede tendrá **dominio propio**, o solo tema propio bajo un mismo dominio?
4. ¿Se modelan **servicios con tiempos muertos** (tintes) desde el MVP? (recomendado: sí).

> Nota: el modelo de precios y políticas de **WhatsApp Business Platform (Meta)** cambia con frecuencia. Verificar tarifas y reglas vigentes antes de cerrar presupuesto.
