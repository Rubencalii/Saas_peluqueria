# Frontend

Frontend del SaaS (Next.js App Router + TypeScript + Tailwind v4) que consume la
API REST del backend (`../backend`, contrato en `../docs/openapi.yaml`). Dos
superficies bajo el mismo proyecto:

- **Web pública de reserva** (rutas en `(public)/`) — el cliente final.
- **Panel de administración** (`/panel`) — el equipo del salón: login JWT, agenda,
  clientes y cuenta/facturación. Núcleo funcional; faltan pantallas de configuración.

## Web pública — flujo

- `/` — elige salón (sedes activas de la cuenta).
- `/[slug]` — servicios del salón y reserva: **servicio → día → hueco → datos → confirmar**.
  El profesional lo asigna el sistema (la API pública no expone el listado de personal).
- `/mi-cita` — consulta por teléfono + código, y **reprograma o cancela**.

Multi-tenant: la cuenta (salón/cadena) la resuelve el backend por el **subdominio**
(en local cae en la cuenta principal). El tema es **white-label** vía variables CSS
(`--brand`, …) en `globals.css`, listo para personalizar por salón (doc 08).

## Desarrollo

Necesita el backend corriendo (por defecto en `http://localhost:8000`):

```bash
# 1) Backend (desde ../backend, con la BD de Docker levantada)
php -S 127.0.0.1:8000 -t public public/index.php

# 2) Frontend
npm install
npm run dev          # http://localhost:3000
```

Las llamadas del navegador van a rutas relativas `/api/...` que Next **reescribe**
al backend (ver `next.config.ts`), así no hay CORS en desarrollo. Para apuntar a
otro backend: `API_BASE=https://api.midominio.com npm run dev`.

**Pago del depósito (opcional):** para que la web ofrezca pagar el depósito tras
reservar (servicios con `deposit_amount`), define la clave pública de Stripe en
`.env.local`: `NEXT_PUBLIC_STRIPE_PK=pk_test_...` (y en el backend `STRIPE_SECRET_KEY`
+ `STRIPE_WEBHOOK_SECRET`). Sin clave pública, el botón de pago no aparece.

## Scripts

- `npm run dev` — desarrollo.
- `npm run build` / `npm start` — build y servidor de producción.
- `npm run lint` — ESLint.
