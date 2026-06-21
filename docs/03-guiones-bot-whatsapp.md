# 03 · Guiones del Bot de WhatsApp

> Diálogos paso a paso para un **bot estructurado** (botones y listas). Los textos son borradores listos para afinar con la voz de marca.

## 1. Principios de diseño del bot

- **Guiado, no abierto**: siempre que se pueda, ofrecer **botones** o **listas** en vez de pedir texto libre. Es más fiable y rápido.
- **Confirmar antes de crear**: nunca crear/cancelar una cita sin un paso de confirmación explícito.
- **Salida a humano**: en cualquier momento, opción de "Hablar con el salón".
- **Memoria de contexto**: recordar al cliente por su teléfono (su nombre, su sede habitual, su última cita).
- **Mensajes cortos**: WhatsApp se lee en móvil; frases breves.

> Recordatorio técnico: las respuestas con texto libre solo se pueden enviar dentro de la **ventana de 24 h** desde el último mensaje del cliente. Los mensajes proactivos (recordatorios) usan **plantillas** (doc 07).

## 2. Menú principal (mensaje de bienvenida)

```
Bot: ¡Hola! 👋 Soy el asistente de [Nombre del salón].
     ¿Qué quieres hacer?

     [📅 Reservar cita]
     [🔍 Ver mi cita]
     [✏️ Cambiar / cancelar]
     [💬 Hablar con el salón]
```

Si el cliente ya es conocido:
```
Bot: ¡Hola de nuevo, [Nombre]! ¿En qué te ayudo?
     [📅 Reservar] [🔍 Mi cita] [✏️ Cambiar] [💬 Hablar]
```

## 3. Flujo: Reservar cita

```
Cliente: [📅 Reservar cita]

Bot: ¿En qué salón quieres tu cita?           ← solo si hay varias sedes
     (lista de sedes)
     • Centro
     • Norte
     • Sur

Cliente: Centro

Bot: ¿Qué servicio quieres?
     (lista de servicios con duración/precio)
     • Corte mujer · 45 min · 18 €
     • Corte hombre · 30 min · 14 €
     • Tinte · 70 min · desde 35 €
     • Peinado · 30 min · 15 €
     • Ver más…

Cliente: Corte mujer

Bot: ¿Con algún profesional en concreto?
     [Sin preferencia] [Laura] [Marta] [Carlos]

Cliente: Sin preferencia

Bot: ¿Qué día te viene bien?
     [Hoy] [Mañana] [Elegir fecha]

Cliente: Mañana

Bot: Para mañana (vie 21) tengo estos huecos:
     (lista)
     • 10:00  • 10:45  • 12:30  • 17:15  • 18:00
     [Ver más horas]

Cliente: 12:30

Bot: Resumen de tu cita 👇
     📍 Centro
     ✂️ Corte mujer (45 min · 18 €)
     👩 Con Laura
     🗓️ Viernes 21 a las 12:30

     ¿Confirmo?
     [✅ Sí, confirmar] [❌ No]

Cliente: ✅ Sí, confirmar

Bot: ¡Listo! Tu cita está confirmada ✅
     Te enviaré un recordatorio el día antes.
     [✏️ Cambiar] [❌ Cancelar]
```

**Si es cliente nuevo** (no tenemos su nombre):
```
Bot: Para terminar, ¿cómo te llamas?
Cliente: María García
Bot: ¡Gracias, María! (continúa con el resumen)
```

## 4. Flujo: Ver mi cita

```
Cliente: [🔍 Ver mi cita]

Bot: Tu próxima cita:
     🗓️ Viernes 21 a las 12:30
     ✂️ Corte mujer · 📍 Centro · 👩 Laura
     [✏️ Cambiar] [❌ Cancelar]
```
Si no hay cita:
```
Bot: No tienes ninguna cita próxima. ¿Quieres reservar?
     [📅 Reservar cita]
```

## 5. Flujo: Cambiar / cancelar

```
Cliente: [✏️ Cambiar / cancelar]

Bot: Tu cita: vie 21 a las 12:30 (Corte mujer, Centro).
     ¿Qué quieres hacer?
     [🔄 Cambiar hora] [❌ Cancelar cita] [↩️ Volver]

— Si CANCELAR —
Bot: ¿Seguro que quieres cancelar tu cita del vie 21 a las 12:30?
     [Sí, cancelar] [No, mantener]
Cliente: Sí, cancelar
Bot: Tu cita ha sido cancelada. ¿Quieres reservar otra?
     [📅 Reservar] [No, gracias]

— Si CAMBIAR HORA —
Bot: ¿Para qué día?  [Mismo día] [Otro día]
     (vuelve al paso de selección de huecos del flujo de reserva)
```

> Política: si falta menos de X horas para la cita, no permitir cancelar self-service y derivar:
```
Bot: Tu cita es en menos de 2 h, no puedo cancelarla por aquí.
     Te paso con el salón 📞 [Llamar] o escribe y te atienden.
```

## 6. Flujo: Hablar con el salón (derivar a humano)

```
Cliente: [💬 Hablar con el salón]

Bot: Te leo y aviso al equipo de [sede]. Cuéntame qué necesitas
     y te responden en cuanto puedan. (horario: L-S 9:00-20:00)
```
- Marcar la conversación para que recepción la vea en el panel (bandeja de "atención humana").
- Si es fuera de horario, avisar de cuándo responderán.

## 7. Casos límite y mensajes de error

| Situación | Respuesta del bot |
|-----------|-------------------|
| No hay huecos ese día | "Ese día está completo 😕 ¿Pruebo otro día?" [Otro día] |
| El hueco se ocupó mientras elegía | "¡Vaya! Ese hueco se acaba de ocupar. Estos siguen libres: …" |
| No entiende el mensaje | "No te he entendido del todo. ¿Quieres reservar, ver tu cita o hablar con el salón?" + botones |
| Cliente escribe fuera de horario | El bot funciona igual (es automático); solo "Hablar con el salón" avisa del horario |
| Fuera de la ventana de 24 h | Solo se puede contactar con plantilla (lo inicia el negocio, no el bot) |

## 8. Variables a parametrizar por sede

- Nombre del salón / sede.
- Horario de atención humana.
- Lista de servicios y profesionales.
- Política de cancelación (horas mínimas de antelación).
- Tono del mensaje (más formal / cercano) según marca de la sede.

## 9. Opcional — versión con IA (fase posterior)

Si más adelante se quiere que el bot entienda texto libre ("quiero un corte el viernes por la tarde con Laura"), se añade una capa de comprensión (LLM) que extrae intención + datos y los mete en este mismo flujo. El flujo estructurado sigue siendo el "raíl" de seguridad para confirmar antes de actuar.
