# 07 · Plantillas de Mensajes de WhatsApp

> Textos borrador para las **plantillas (HSM)** que requieren aprobación de Meta y para los mensajes interactivos dentro de la conversación. Adaptar a la voz de cada marca.

## 1. ¿Por qué plantillas?

Los mensajes que **inicia el negocio** (confirmaciones programadas, recordatorios, avisos) fuera de la ventana de 24 h **deben usar plantillas pre-aprobadas por Meta**. Conviene:
- Definirlas pronto y enviarlas a aprobación (puede tardar y pueden rechazarse).
- Categorizarlas correctamente (utilidad/marketing/autenticación) porque afecta a coste y aprobación.
- Usar **variables** (`{{1}}`, `{{2}}`…) para los datos dinámicos.

> Verificar las categorías y reglas vigentes de Meta antes de enviarlas (cambian con frecuencia).

## 2. Plantillas de utilidad (transaccionales)

### 2.1 Confirmación de cita
```
Nombre: cita_confirmacion
Categoría: Utilidad
Idioma: es

Cuerpo:
¡Hola {{1}}! ✅ Tu cita en {{2}} está confirmada:
🗓️ {{3}} a las {{4}}
✂️ {{5}}
Si necesitas cambiarla, responde a este mensaje.

Botones (respuesta rápida):
[Cambiar] [Cancelar]
```
Variables: 1=nombre, 2=sede, 3=fecha, 4=hora, 5=servicio.

### 2.2 Recordatorio (24 h antes)
```
Nombre: cita_recordatorio_24h
Categoría: Utilidad
Idioma: es

Cuerpo:
¡Hola {{1}}! 👋 Te recordamos tu cita de mañana en {{2}}:
🗓️ {{3}} a las {{4}} · {{5}}
¿Confirmas que vendrás?

Botones:
[Sí, allí estaré] [Necesito cambiarla]
```

### 2.3 Recordatorio (2 h antes) — opcional
```
Nombre: cita_recordatorio_2h
Categoría: Utilidad
Cuerpo:
{{1}}, tu cita en {{2}} es hoy a las {{3}}. ¡Te esperamos! 💈
```

### 2.4 Aviso de cambio / cancelación desde el salón
```
Nombre: cita_cambio_salon
Categoría: Utilidad
Cuerpo:
Hola {{1}}, hemos tenido que {{2}} tu cita del {{3}} a las {{4}}.
Lo sentimos. Responde a este mensaje y te ayudamos a reubicarla.
```
Variable 2 = "modificar" / "cancelar".

### 2.5 Seguimiento post-cita — opcional
```
Nombre: cita_seguimiento
Categoría: Utilidad / Marketing (según contenido)
Cuerpo:
¡Gracias por tu visita, {{1}}! 💛 ¿Qué tal la experiencia en {{2}}?
[Muy bien] [Regular] [Reservar otra vez]
```

### 2.6 "Te toca volver" (retención) — opcional, marketing
```
Nombre: recordatorio_retorno
Categoría: Marketing  (requiere consentimiento explícito)
Cuerpo:
Hola {{1}}, ¿hace ya unas semanas de tu último corte? ✂️
Reserva cuando quieras: {{2}}
```
> Las plantillas de **marketing** necesitan consentimiento y pueden tener restricciones/coste distinto.

## 3. Mensajes interactivos (dentro de la conversación)

Estos **no son plantillas** (van dentro de la ventana de 24 h abierta por el cliente) y pueden usar botones y listas libremente. Sus textos están en los guiones del doc 03. Tipos que se usarán:
- **Listas**: elegir sede, servicio, hueco horario.
- **Botones de respuesta rápida**: confirmar, cambiar, cancelar, "sin preferencia".

## 4. Buenas prácticas de redacción
- Frases cortas, un emoji por mensaje como máximo.
- Incluir siempre los datos clave: sede, fecha, hora, servicio.
- Dar salida clara a "hablar con una persona".
- Personalizar con el nombre cuando se tenga.
- Mantener coherencia con el tono de la marca de cada sede (variable de configuración).

## 5. Checklist antes de lanzar
- [ ] Número de WhatsApp Business verificado.
- [ ] Plantillas creadas y **aprobadas** por Meta.
- [ ] Variables mapeadas a los datos del sistema.
- [ ] Programación de recordatorios en el worker de jobs.
- [ ] Consentimiento de WhatsApp registrado por cliente (RGPD, doc 09).
- [ ] Pruebas de envío en número real.
