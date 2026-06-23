# 08 · Multi-tenant y Diseño Propio por Sede (White-label)

> Responde a la pregunta: *"¿puede ser el mismo backend pero con diseño propio?"* → **Sí.** Aquí está cómo.

> **Estado (2026-06-23):** implementado a nivel de **cuenta** (empresa). Cada cuenta
> guarda `display_name`, `brand_color`, `accent_color` y `logo_url` (migración 0020);
> el admin los edita en *Panel → Apariencia* (`PATCH /api/v1/admin/account/branding`)
> y la web de reserva los lee por subdominio (`GET /api/v1/branding`). El frontend
> deriva toda la paleta del color de marca. La tematización **por sede** (más fina)
> y el **dominio propio** quedan como ampliación.

## 1. La idea

Un **único backend** y una **única lógica de negocio**, pero la "piel" (logo, colores, tipografía, imágenes, incluso el dominio) **cambia por sede o marca**. Es un patrón estándar llamado **multi-tenant con tematización (white-label)**.

```
        Tema A            Tema B            Tema C
   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
   │  Sede Centro │  │  Sede Norte  │  │  Sede Sur    │
   │  (azul/logo) │  │ (verde/logo) │  │ (negro/logo) │
   └──────┬───────┘  └──────┬───────┘  └──────┬───────┘
          └─────────────────┼─────────────────┘
                            ▼
                ┌────────────────────────┐
                │  MISMO Backend / API    │
                │  (lógica compartida)    │
                └────────────────────────┘
```

## 2. Tres niveles (de menos a más esfuerzo)

### Nivel 1 — Tematización por configuración (recomendado)
Un mismo frontend que **carga un tema distinto según la sede**: colores, logo, fuente, textos, imágenes. El código es el mismo; solo cambia un objeto de configuración por sede.
- ✅ Una sola base de código que mantener.
- ✅ Cada sede se ve distinta.
- ✅ Añadir una sede nueva = añadir su tema, sin programar.

### Nivel 2 — Dominio propio por sede
Cada sede puede tener su **propia URL** (`reservas.peluqueria-centro.com`, `citas.salonnorte.com`) apuntando al mismo backend. El sistema detecta la sede por el dominio y aplica su tema.
- ✅ Cada sede percibe su web como "suya".
- ➕ Requiere gestión de dominios/DNS y certificados.

### Nivel 3 — Frontend totalmente independiente por sede
Webs realmente distintas que consumen la misma API.
- ✅ Libertad de diseño máxima.
- ❌ Multiplica el mantenimiento. Solo si las sedes son marcas muy diferentes.

> **Recomendación para una cadena:** Nivel 1 (y Nivel 2 si quieren dominios propios). Cubre el 95 % de los casos con el mínimo coste de mantenimiento.

## 3. Cómo se implementa (Nivel 1 + 2)

### 3.1 Identificar la sede (el "tenant")
El sistema necesita saber a qué sede pertenece cada visita. Opciones:
- Por **ruta**: `misalon.com/centro`, `misalon.com/norte`.
- Por **subdominio**: `centro.misalon.com`.
- Por **dominio propio**: `reservas-centro.com` → mapeado a la sede en `branding.custom_domain`.

### 3.2 Cargar el tema
Al resolver la sede, el frontend pide su branding a la API:
```
GET /api/v1/locations/{slug}/branding
→ { logo_url, color_primary, color_accent, font_family, ... }
```
Y aplica esos valores mediante **variables CSS** (custom properties), de modo que los componentes usan `var(--color-primary)` en vez de colores fijos.

```css
:root {
  --color-primary: #1A1A1A;   /* viene del branding de la sede */
  --color-accent:  #C8A86B;
  --font-base:     'Inter', sans-serif;
}
```

### 3.3 Datos del tema en la base de datos
Ya previsto en el doc 05, tabla `branding`:
```
branding(location_id, logo_url, color_primary, color_accent,
         font_family, custom_domain, extra JSONB)
```

## 4. Qué se comparte y qué cambia

| Elemento | Compartido (backend) | Propio por sede (tema) |
|----------|:--------------------:|:----------------------:|
| Lógica de disponibilidad | ✅ | |
| Base de datos / citas | ✅ | |
| API y reglas de negocio | ✅ | |
| Bot de WhatsApp (motor) | ✅ | (textos/tono parametrizables) |
| Logo, colores, tipografía | | ✅ |
| Imágenes / fotos | | ✅ |
| Dominio / URL | | ✅ (opcional) |
| Servicios y precios | ✅ base | ✅ pueden personalizarse por sede |
| Tono de los mensajes | ✅ motor | ✅ texto por sede |

## 5. Implicaciones de diseño (importante)
- **Decidir esto al principio**: aunque el branding sea "estético", afecta a la arquitectura (resolución de tenant, variables CSS, tabla branding). Reescribir esto a mitad cuesta.
- Construir los componentes del frontend **sin colores ni logos fijos**: todo desde variables/configuración.
- El bot de WhatsApp comparte motor, pero sus textos (nombre del salón, tono) se parametrizan por sede.
- Para WhatsApp: una cadena puede usar **un único número** (y el bot identifica la sede en la conversación) o **un número por sede** (más caro y complejo, pero cada sede tiene su línea). Decidir según presupuesto.

## 6. Resumen
Sí, es perfectamente viable y además es lo recomendable: **un backend, muchas pieles**. Empezar por tematización por configuración (Nivel 1) y añadir dominios propios (Nivel 2) si el negocio lo pide. Dejar la arquitectura preparada para el tenant desde el día uno.
