# 09 · RGPD y Privacidad

> Aplica al tratar datos personales (nombre, teléfono, email, historial de citas) de clientes en España/UE. **No es asesoramiento legal**: conviene revisar con un especialista en protección de datos antes del lanzamiento.

## 1. Marco aplicable
- **RGPD** (Reglamento General de Protección de Datos, UE 2016/679).
- **LOPDGDD** (Ley Orgánica 3/2018, España).
- **LSSI-CE** para comunicaciones comerciales electrónicas.
- Reglas de **WhatsApp/Meta** como encargado del tratamiento.

## 2. Bases legales del tratamiento

| Finalidad | Base legal sugerida |
|-----------|---------------------|
| Gestionar la cita (reserva, recordatorio operativo) | Ejecución de un servicio / contrato |
| Enviar avisos por WhatsApp | **Consentimiento** explícito |
| Comunicaciones comerciales ("vuelve", ofertas) | **Consentimiento** específico e independiente |
| Historial de cliente / notas | Interés legítimo o consentimiento (valorar) |

## 3. Consentimiento (puntos clave)
- **Casilla NO premarcada** en la web para "Acepto recibir avisos por WhatsApp".
- Consentimiento **separado** para avisos operativos vs. marketing.
- En el bot, registrar la aceptación inicial de forma clara.
- Guardar **prueba del consentimiento**: fecha, hora, canal (campos `wa_consent`, `consent_at` del doc 05).
- Permitir **retirar el consentimiento** fácilmente (p. ej. responder "BAJA").

## 4. Principios a cumplir
- **Minimización**: pedir solo lo necesario (nombre + teléfono; email opcional).
- **Limitación de finalidad**: usar los datos solo para lo informado.
- **Limitación del plazo de conservación**: definir cuánto se guardan los datos y el historial (p. ej. mientras sea cliente + X años; luego anonimizar/eliminar).
- **Exactitud**: permitir corregir datos.
- **Integridad y confidencialidad**: cifrado en tránsito (HTTPS) y en reposo; acceso por rol.

## 5. Derechos de las personas
Habilitar (vía web/email de contacto) el ejercicio de:
- Acceso, rectificación, supresión ("derecho al olvido").
- Oposición y limitación del tratamiento.
- Portabilidad.
Definir un procedimiento y un plazo de respuesta (máx. 1 mes).

## 6. Documentos y medidas necesarios
- **Política de privacidad** accesible en la web (y enlazada en el bot).
- **Aviso legal** y, si hay cookies/analítica, **política de cookies** + banner conforme.
- **Registro de actividades de tratamiento** (RAT).
- **Contratos de encargado del tratamiento** con: proveedor cloud, BSP de WhatsApp, Meta (condiciones), cualquier subencargado.
- Evaluar si procede una **Evaluación de Impacto (EIPD)** según el volumen/sensibilidad.
- Medidas de seguridad: control de accesos, copias de seguridad, registro de actividad (auditoría).

## 7. Específico de WhatsApp / Meta
- Meta actúa como **encargado/subencargado**: revisar y aceptar sus condiciones de tratamiento.
- Datos pueden tratarse fuera de la UE: verificar **garantías de transferencia internacional** vigentes.
- Informar al cliente de que el canal de comunicación es WhatsApp (Meta) en la política de privacidad.

## 8. Brechas de seguridad
- Procedimiento para detectar y, si procede, **notificar a la AEPD** en 72 h y a los afectados cuando corresponda.

## 9. Checklist resumen
- [ ] Política de privacidad y aviso legal publicados.
- [ ] Consentimiento WhatsApp separado, no premarcado y registrado.
- [ ] Mecanismo de baja ("BAJA") operativo.
- [ ] Plazos de conservación definidos.
- [ ] Contratos de encargado firmados (cloud, BSP, Meta).
- [ ] Cifrado en tránsito y reposo.
- [ ] Procedimiento de derechos y de brechas.
- [ ] Revisión por asesor de protección de datos antes de lanzar.
