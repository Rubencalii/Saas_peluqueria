# 04 · Wireframes y Flujos (Web + Panel)

> Bocetos en baja fidelidad (ASCII) y descripción de pantallas. Sirven para alinear navegación antes de diseñar en detalle.

## 1. Web pública de reserva (cliente)

### 1.1 Flujo

```
Inicio → Sede → Servicio → Profesional → Día/Hora → Datos → Confirmación
```
Cada paso ocupa una pantalla en móvil; en escritorio pueden agruparse en columnas.

### 1.2 Pantalla: selección de servicio (ejemplo móvil)

```
┌───────────────────────────┐
│ [logo sede]      Centro ▾ │   ← selector de sede
├───────────────────────────┤
│  Reserva tu cita           │
│                            │
│  ¿Qué necesitas?           │
│  ┌───────────────────────┐ │
│  │ Corte mujer           │ │
│  │ 45 min · 18 €      ›  │ │
│  ├───────────────────────┤ │
│  │ Corte hombre          │ │
│  │ 30 min · 14 €      ›  │ │
│  ├───────────────────────┤ │
│  │ Tinte                 │ │
│  │ 70 min · desde 35 € › │ │
│  └───────────────────────┘ │
└───────────────────────────┘
```

### 1.3 Pantalla: selección de fecha/hora

```
┌───────────────────────────┐
│ ‹ Corte mujer · Laura      │
├───────────────────────────┤
│  < Junio 2026 >            │
│  L  M  X  J  V  S  D       │
│           19 20 [21] 22    │
│                            │
│  Horas — vie 21            │
│  [10:00][10:45][12:30]     │
│  [17:15][18:00]            │
│                            │
│         [ Continuar ]      │
└───────────────────────────┘
```

### 1.4 Pantalla: datos + confirmación

```
┌───────────────────────────┐
│  Casi está                 │
│  Nombre  [______________]  │
│  Móvil   [______________]  │
│  Email   [______________]  │  (opcional)
│  □ Acepto recibir avisos   │  ← consentimiento WhatsApp (RGPD)
│    por WhatsApp            │
│  □ He leído la política    │
│    de privacidad           │
│         [ Reservar ]       │
└───────────────────────────┘
```

### 1.5 Buenas prácticas de la web pública
- Responsive real (probar en móvil de gama baja).
- Reservar en el menor número de toques posible.
- Mostrar duración y precio en cada servicio.
- SEO básico: la web pública debe indexarse (Next.js SSR ayuda).
- Botón directo "Reservar por WhatsApp" (enlace `wa.me`) como alternativa.

## 2. Panel de administración (interno)

### 2.1 Navegación

```
[Sidebar]
 • Agenda           (vista principal)
 • Clientes
 • Servicios
 • Profesionales
 • Sedes            (solo admin de cadena)
 • Informes
 • Conversaciones   (bandeja de WhatsApp "atención humana")
 • Ajustes / Usuarios
```

### 2.2 Vista de agenda (día)

```
┌──────────────────────────────────────────────┐
│ Sede: Centro ▾   < Vie 21 jun >   [Día][Semana]│
├──────┬───────────┬───────────┬─────────────────┤
│ Hora │  Laura    │  Marta    │  Carlos         │
├──────┼───────────┼───────────┼─────────────────┤
│ 10:00│ M. García │           │ Tinte (J. Pérez)│
│      │ Corte 45m │           │  ░ espera ░     │
│ 11:00│           │ Mechas    │ (libre p/ otro) │
│ 12:30│ Corte     │           │                 │
└──────┴───────────┴───────────┴─────────────────┘
   ░ = segmento de espera de un servicio con tiempos muertos
```
- Columnas por profesional; filas por franja horaria.
- Citas como bloques arrastrables (mover = reprogramar).
- Color por estado (confirmada / pendiente / no-show).
- Botón "+ Nueva cita" para reservas telefónicas/presenciales.

### 2.3 Ficha de cliente

```
┌───────────────────────────┐
│ María García               │
│ 📱 6XX XXX XXX             │
│ ✉️ maria@...   (opcional)  │
│ Consentimiento WhatsApp: ✅ │
├───────────────────────────┤
│ Historial de citas         │
│ • 21/06 Corte (Centro)     │
│ • 03/05 Tinte (Centro)     │
│ Notas internas: …          │
└───────────────────────────┘
```

### 2.4 Conversaciones (bandeja WhatsApp)
- Lista de chats marcados como "atención humana".
- Permite a recepción responder dentro de la ventana de 24 h.
- Estado: pendiente / atendido.

## 3. Flujos de usuario (resumen)

**Reserva web**: Sede → Servicio → Profesional → Día/Hora → Datos → Confirmar → notificación WhatsApp.

**Reserva telefónica (recepción)**: Panel → + Nueva cita → buscar/crear cliente → servicio → hueco → guardar.

**Reprogramar (recepción)**: arrastrar la cita a otro hueco → el sistema valida y notifica al cliente.

**No-show**: recepción marca la cita como `no-show` → alimenta el informe de ausencias.

## 4. Sobre el diseño propio por sede
La estructura de pantallas es la misma para todas las sedes; lo que cambia es el **tema** (logo, colores, tipografía). Ver doc 08 para cómo se implementa. Diseñar los componentes pensando en que los colores y el logo vienen de configuración, no fijos en el código.
