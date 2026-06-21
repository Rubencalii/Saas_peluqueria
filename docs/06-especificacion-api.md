# 06 · Especificación de la API

> API REST orientativa que comparten **web** y **bot de WhatsApp**. Es el núcleo único. Formato sugerido para documentar formalmente: **OpenAPI 3 (Swagger)**.

## 1. Principios

- **Una sola API** para todos los canales. La web y el servicio de WhatsApp son clientes de esta API.
- **Autenticación**:
  - Endpoints **públicos** (reserva del cliente): sin login, pero protegidos (rate limiting, validación, captcha si hace falta).
  - Endpoints **internos** (panel): autenticados con token (JWT) y autorizados por rol.
- **Multi-sede**: la sede se identifica por parámetro (`location_id` o `slug`) o por dominio (ver doc 08).
- **Horas**: la API trabaja en **UTC** (ISO 8601); el cliente convierte a hora local de la sede.
- **Versionado**: prefijo `/api/v1`.

## 2. Endpoints públicos (cliente / reserva)

### Catálogo
```
GET  /api/v1/locations
     → lista de sedes activas (id, nombre, slug)

GET  /api/v1/locations/{slug}/services
     → servicios disponibles en esa sede (con duración y precio)

GET  /api/v1/locations/{slug}/staff?service_id=
     → profesionales que ofrecen ese servicio en la sede
```

### Disponibilidad
```
GET  /api/v1/availability
     params: location_id, service_id, staff_id? (opcional), date (YYYY-MM-DD)
     → {
         "date": "2026-06-21",
         "slots": [
           { "start": "2026-06-21T10:00:00+02:00", "staff_id": 3 },
           { "start": "2026-06-21T10:45:00+02:00", "staff_id": 3 }
         ]
       }
```

### Crear reserva
```
POST /api/v1/appointments
     body: {
       "location_id": 1,
       "service_id": 5,
       "staff_id": 3,           // o null = sin preferencia
       "start": "2026-06-21T10:45:00+02:00",
       "customer": { "name": "María García", "phone": "+346...", "email": null },
       "wa_consent": true,
       "channel": "web"          // o "whatsapp"
     }
     → 201 { "appointment_id": 123, "status": "confirmada", ... }
     → 409 si el hueco ya está ocupado (condición de carrera)
```

### Consultar / gestionar la propia cita
```
GET    /api/v1/appointments/lookup?phone=+346...&code=...
       → próxima(s) cita(s) del cliente (con verificación ligera)

PATCH  /api/v1/appointments/{id}/reschedule
       body: { "start": "..." }
       → reprograma (valida hueco atómicamente)

DELETE /api/v1/appointments/{id}
       → cancela (respeta política de antelación)
```

> Para identificar al cliente sin login se puede usar el teléfono + un código enviado por WhatsApp, o un enlace firmado (token de un solo uso) incluido en la confirmación. Decidir el mecanismo en diseño técnico.

## 3. Webhook de WhatsApp

```
POST /api/v1/webhooks/whatsapp
     ← Meta envía aquí los mensajes entrantes del cliente.
     El servicio del bot interpreta el mensaje, llama internamente a los
     mismos endpoints de disponibilidad/reserva y responde vía Cloud API.

GET  /api/v1/webhooks/whatsapp
     ← verificación del webhook (handshake de Meta con verify_token).
```

## 4. Endpoints internos (panel · requieren auth + rol)

```
# Agenda
GET    /api/v1/admin/agenda?location_id=&date=&view=day|week
POST   /api/v1/admin/appointments            (cita manual/telefónica)
PATCH  /api/v1/admin/appointments/{id}        (editar, cambiar estado, no_show)
DELETE /api/v1/admin/appointments/{id}

# Clientes
GET    /api/v1/admin/customers?query=
GET    /api/v1/admin/customers/{id}
PATCH  /api/v1/admin/customers/{id}

# Configuración (según rol)
GET/POST/PATCH  /api/v1/admin/services
GET/POST/PATCH  /api/v1/admin/staff
GET/POST/PATCH  /api/v1/admin/staff/{id}/schedule
GET/POST        /api/v1/admin/time-blocks
GET/POST/PATCH  /api/v1/admin/locations        (solo admin_cadena)
GET/PATCH       /api/v1/admin/locations/{id}/branding   (diseño propio)

# Conversaciones WhatsApp (atención humana)
GET    /api/v1/admin/conversations?status=pendiente
POST   /api/v1/admin/conversations/{id}/reply

# Informes
GET    /api/v1/admin/reports/occupancy?location_id=&from=&to=
GET    /api/v1/admin/reports/no-shows?location_id=&from=&to=
GET    /api/v1/admin/reports/bookings-by-channel?...
```

## 5. Autorización por rol (resumen)

| Recurso | recepción | profesional | admin_sede | admin_cadena |
|---------|:--------:|:-----------:|:----------:|:------------:|
| Agenda de su sede | ✅ | solo la suya | ✅ | ✅ (todas) |
| Crear/editar citas | ✅ | limitado | ✅ | ✅ |
| Configurar servicios/horarios | ❌ | ❌ | ✅ (su sede) | ✅ |
| Gestionar sedes | ❌ | ❌ | ❌ | ✅ |
| Branding por sede | ❌ | ❌ | parcial | ✅ |
| Informes consolidados | ❌ | ❌ | su sede | ✅ |

## 6. Errores y convenciones

- Códigos HTTP estándar: `200/201` ok, `400` validación, `401/403` auth, `404` no existe, `409` conflicto (hueco ocupado), `429` rate limit.
- Respuesta de error uniforme:
  ```json
  { "error": { "code": "SLOT_TAKEN", "message": "Ese hueco ya está ocupado" } }
  ```
- **Rate limiting** en endpoints públicos para evitar abuso/scraping.
- **Idempotencia** en `POST /appointments` (clave de idempotencia) para evitar reservas duplicadas por doble toque.

## 7. Documentación formal recomendada
Generar un archivo **OpenAPI** (`openapi.yaml`) a partir de esto, que sirva de contrato entre frontend, bot y backend, y permita generar clientes y pruebas automáticas.
