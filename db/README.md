# Base de datos

Capa de datos del sistema de reservas (PostgreSQL 14+). Implementa el modelo
de `docs/05-modelo-datos-sql.md` y la lógica anti-doble-reserva de
`docs/02-logica-disponibilidad.md`.

## Estructura

```
db/
├── migrations/
│   ├── 0001_init.sql              # tablas, enums, índices
│   └── 0002_overlap_protection.sql# tramos ocupados + restricción anti-solape
├── seed.sql                       # datos de demostración
└── tests/
    └── overlap_and_deadtime.sql   # prueba manual de concurrencia y tiempos muertos
```

## Arrancar con Docker (recomendado en Windows)

```bash
docker compose up -d        # crea Postgres y aplica migraciones + seed
```

Cadena de conexión: `postgres://peluqueria:peluqueria@localhost:5446/peluqueria`
(puerto 5446 en el host para no chocar con otros Postgres locales)

> Las migraciones y el seed sólo se aplican en el **primer** arranque (volumen
> vacío). Para reaplicar desde cero:
> ```bash
> docker compose down -v && docker compose up -d
> ```

## Aplicar manualmente (si ya tienes Postgres)

```bash
export DATABASE_URL="postgres://usuario:clave@localhost:5432/peluqueria"
psql "$DATABASE_URL" -f db/migrations/0001_init.sql
psql "$DATABASE_URL" -f db/migrations/0002_overlap_protection.sql
psql "$DATABASE_URL" -f db/seed.sql
```

## Probar la lógica crítica

```bash
docker compose exec -T db psql -U peluqueria -d peluqueria \
  < db/tests/overlap_and_deadtime.sql
```

La prueba verifica (todo en una transacción que se revierte):

1. Dos citas solapadas del mismo profesional → la 2ª **falla** (restricción de exclusión).
2. Mismo hueco con **otro** profesional → permitido.
3. Tinte segmentado → sólo los tramos activos (20 min + 15 min) ocupan agenda.
4. Cita corta **durante el reposo** del tinte → permitida (recupera ingresos).
5. Cita que pisa un tramo **activo** del tinte → falla.
6. Cancelar una cita → libera sus tramos automáticamente.

## Decisiones de diseño

- **Disponibilidad calculada, no almacenada** (doc 02): los huecos se derivan de
  horario − citas − bloqueos − margen.
- **Anti-doble-reserva en la BD**: la restricción de exclusión (`btree_gist` +
  `EXCLUDE USING gist`) se aplica sobre `appointment_busy_block` (tramos
  realmente ocupados), no sobre el rango completo de la cita. Así un servicio con
  tiempos muertos (tinte) deja al profesional libre durante el reposo, pero la
  base de datos sigue rechazando cualquier solape real, incluso con peticiones
  simultáneas de web y WhatsApp.
- **Horas en UTC** (`TIMESTAMPTZ`); la presentación en hora local de la sede es
  responsabilidad del cliente/API.
- Un **trigger** mantiene `appointment_busy_block` sincronizado con el estado y
  los segmentos del servicio.
