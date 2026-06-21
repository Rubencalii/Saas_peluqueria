# 10 · Plan de Pruebas y Criterios de Aceptación

> Qué hay que verificar para considerar cada función "terminada". Sirve de checklist de QA y de criterios de aceptación con el cliente.

## 1. Tipos de prueba
- **Unitarias**: lógica de disponibilidad, cálculo de huecos, validaciones.
- **Integración**: API + base de datos (reserva, cancelación, concurrencia).
- **End-to-end (E2E)**: flujo completo web y flujo completo del bot.
- **Carga/concurrencia**: dos reservas simultáneas al mismo hueco.
- **Manuales/UAT**: validación del cliente con datos reales.

## 2. Criterios de aceptación por área

### 2.1 Reserva web
- [ ] El cliente puede reservar eligiendo sede, servicio, profesional y hora.
- [ ] Solo se muestran huecos realmente disponibles.
- [ ] No se puede reservar en el pasado ni por debajo de la antelación mínima.
- [ ] Tras confirmar, llega la confirmación por WhatsApp.
- [ ] La web es usable en móvil (probada en pantalla pequeña).

### 2.2 Disponibilidad y concurrencia (crítico)
- [ ] Dos reservas simultáneas al mismo hueco: **solo una** tiene éxito; la otra recibe 409.
- [ ] Un servicio largo ocupa los slots correctos y bloquea solapamientos.
- [ ] Servicio con tiempo muerto: el profesional aparece libre durante la espera.
- [ ] Cancelar una cita libera el hueco inmediatamente.
- [ ] Los bloqueos (vacaciones/descansos) no aparecen como disponibles.

### 2.3 Bot de WhatsApp
- [ ] Flujo de reserva completo por botones/listas funciona de principio a fin.
- [ ] Consultar la próxima cita devuelve la correcta.
- [ ] Cancelar y reprogramar funcionan y notifican.
- [ ] "Hablar con el salón" marca la conversación para atención humana.
- [ ] Casos límite (sin huecos, hueco ocupado al confirmar, mensaje no entendido) responden bien.
- [ ] Se respeta la ventana de 24 h; los recordatorios usan plantilla.

### 2.4 Notificaciones
- [ ] Confirmación se envía al crear la cita (cualquier canal).
- [ ] Recordatorio 24 h (y 2 h si aplica) se envía a su hora.
- [ ] Aviso de cambio/cancelación desde el salón llega al cliente.
- [ ] Reintentos ante fallo de envío; estado registrado.

### 2.5 Panel de administración
- [ ] Agenda día/semana correcta por sede y profesional.
- [ ] Crear, editar, mover (reprogramar) y cancelar citas.
- [ ] Marcar no-show alimenta el informe.
- [ ] Gestión de clientes, servicios, profesionales y horarios.
- [ ] Permisos por rol respetados (un usuario no ve/edita lo que no debe).

### 2.6 Multi-sede y branding
- [ ] Cada sede muestra su tema (logo/colores) correctamente.
- [ ] El cliente es único por teléfono en toda la cadena.
- [ ] Servicios/precios específicos por sede se aplican bien.
- [ ] (Si aplica) dominio propio por sede resuelve al tema correcto.

### 2.7 Seguridad y RGPD
- [ ] Todo el tráfico por HTTPS.
- [ ] Endpoints internos exigen autenticación y rol.
- [ ] Rate limiting en endpoints públicos.
- [ ] Consentimiento WhatsApp se registra y se puede retirar.
- [ ] Datos sensibles cifrados en reposo.

## 3. Pruebas de zona horaria
- [ ] Una cita creada se muestra a la misma hora local tras cambio de horario verano/invierno.
- [ ] Sedes en husos distintos (si las hubiera) muestran su hora local.

## 4. Definición de "Hecho" (Definition of Done)
Una función se considera terminada cuando:
1. Pasa sus pruebas automáticas.
2. Cumple sus criterios de aceptación.
3. Está documentada (API/manual si aplica).
4. Revisada por otra persona (code review).
5. Validada en entorno de pruebas por el cliente (UAT) para funciones clave.
