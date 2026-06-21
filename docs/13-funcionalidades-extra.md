# 13 · Funcionalidades Adicionales (Backlog)

> Mejoras más allá del alcance inicial, con una priorización orientativa. Pensadas específicamente para el sector peluquería.

## 1. Priorización sugerida

| Prioridad | Funcionalidad | Por qué |
|-----------|---------------|---------|
| Alta | Servicios con tiempos muertos (tintes) | Recupera ingresos perdidos (ver doc 02) |
| Alta | Política y recordatorios anti no-show | Impacto directo en ocupación |
| Media | Recordatorio "te toca volver" (retención) | Recupera clientes, muy rentable |
| Media | Lista de espera | Rellena huecos liberados |
| Media | Pago online / depósito | Reduce no-shows; añade complejidad |
| Media | Sincronización con Google Calendar | Comodidad para profesionales |
| Baja | Valoración post-cita | Feedback y reputación |
| Baja | Fidelización / puntos | Retención a largo plazo |
| Baja | NLU en el bot (texto libre) | Mejora UX del bot |
| Baja | App nativa | La web responsive ya cubre móvil |

## 2. Detalle de las más relevantes

### 2.1 Servicios con tiempos muertos
Ya descrito en el doc 02. Modelar el servicio como segmentos activo/espera para que el profesional pueda atender a otra persona durante el reposo del tinte. **Recomendado incluso en el MVP** porque afecta al modelo de datos.

### 2.2 Política anti no-show
- Confirmación con botón "Sí, allí estaré" en el recordatorio.
- Ventana mínima de cancelación.
- Más adelante: pequeño **depósito** reembolsable que se pierde si no se cancela a tiempo (requiere pago online).

### 2.3 Recordatorio de retorno ("te toca volver")
Mensaje automático a las X semanas de la última visita ("¿hace ya un mes de tu último corte?"). Es de las acciones con mejor retorno en peluquería. Requiere consentimiento de marketing (doc 09).

### 2.4 Lista de espera
Si un hueco deseado está ocupado, el cliente puede apuntarse; al liberarse, el sistema avisa al primero de la lista. Aumenta la ocupación sin esfuerzo del personal.

### 2.5 Pago online / depósito
- Integración con pasarela de pago.
- Casos: pago total anticipado, depósito parcial, o solo "tarjeta en garantía".
- Cambia el flujo de reserva y añade requisitos legales/fiscales. Decidir pronto si entra (afecta al diseño).

### 2.6 Sincronización con calendario
Exportar/sincronizar la agenda del profesional con Google Calendar para que la vean donde ya trabajan.

### 2.7 Canales de captación
- Botón "Reservar" en **Instagram** y en la **ficha de Google** del negocio.
- Enlaces `wa.me` con mensaje predefinido para iniciar el bot.

### 2.8 Informes avanzados
- Ingresos por profesional (útil si hay comisiones).
- Servicios más demandados, horas punta, tasa de retención.
- Comparativa entre sedes (para admin de cadena).

## 3. Cómo decidir qué entra y cuándo
1. Cerrar el **MVP** (docs 01–08) y ponerlo en marcha.
2. Medir con datos reales (KPIs del doc 01).
3. Priorizar el backlog según lo que más duela: si hay muchos no-shows → anti no-show + depósito; si baja la repetición → recordatorio de retorno; etc.
4. Revisar este backlog cada cierto tiempo con el cliente.
