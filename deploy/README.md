# Despliegue de producción (Docker Compose)

Materializa el doc **11-despliegue-devops.md**: un host con Docker sirve todo
(BD, API, panel/web, tareas programadas, backups y TLS). Para volúmenes mayores,
mover la BD a un Postgres gestionado y repartir servicios.

## Piezas

| Servicio | Qué es | Imagen |
|---|---|---|
| `caddy` | Entrada única 80/443, TLS automático, enruta `/api/*` → backend y el resto → frontend | `caddy:2` |
| `frontend` | Next.js standalone (`node server.js`) | `frontend/Dockerfile` |
| `backend` | Symfony + Apache (php 8.5, opcache, `FallbackResource`) | `backend/Dockerfile` |
| `scheduler` | Bucle de crons: notificaciones, lista de espera, recurrentes; retorno + purga 1×/día (~04h) | misma imagen que backend |
| `db` | PostgreSQL 16 con volumen persistente | `postgres:16` |
| `backup` | `pg_dump -Fc` diario a `./backups` con retención (14 días por defecto) | `postgres:16` |

## Primera puesta en marcha

1. **Variables del compose** — crea `.env` en la raíz (gitignorado):

   ```env
   DOMAIN=reservas.tu-dominio.com
   DB_PASSWORD=<contraseña larga del owner>
   # opcionales: DB_USER, DB_NAME, BACKUP_RETENTION_DAYS
   ```

2. **Configuración del backend** — copia `backend/.env.prod.example` a
   `backend/.env.prod` (gitignorado) y rellénalo. Claves:
   - `APP_SECRET` aleatorio ≥32 chars (`php -r "echo bin2hex(random_bytes(32));"`).
   - `DATABASE_URL` con el rol **restringido** `peluqueria_app` y host `db`:
     `postgresql://peluqueria_app:<pass_app>@db:5432/peluqueria?serverVersion=16&charset=utf8`
   - `APP_URL=https://<DOMAIN>` y `CORS_ALLOWED_ORIGINS=https://<DOMAIN>`.

3. **Arrancar**: `docker compose -f docker-compose.prod.yml up -d --build`

4. **Migraciones** (la BD nace vacía). Se aplican con el **owner**, no con el rol
   restringido:

   ```bash
   docker compose -f docker-compose.prod.yml exec \
     -e DATABASE_URL="postgresql://peluqueria:<DB_PASSWORD>@db:5432/peluqueria?serverVersion=16&charset=utf8" \
     backend php bin/console app:db:migrate
   ```

5. **Contraseña del rol de la app** (la migración de RLS crea `peluqueria_app`
   sin contraseña):

   ```bash
   docker compose -f docker-compose.prod.yml exec db \
     psql -U peluqueria -d peluqueria -c "ALTER ROLE peluqueria_app LOGIN PASSWORD '<pass_app>'"
   ```

6. **Primera cuenta**: entra en `https://<DOMAIN>/alta` y crea el salón.

7. Repasa el **checklist** del doc 11 §10 (webhook WhatsApp, plantillas Meta,
   TRUSTED_PROXIES, prueba E2E real…).

## Actualizar a una versión nueva

```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build   # reconstruye lo cambiado
# si hay migraciones nuevas → paso 4 de arriba
```

## Backups y restauración

- Diarios en `./backups/peluqueria_<fecha>.dump` (formato custom de pg_dump).
- **Prueba la restauración** periódicamente (doc 11 §6):

  ```bash
  # restaurar en una BD temporal para verificar el backup
  docker compose -f docker-compose.prod.yml exec db createdb -U peluqueria restore_test
  docker compose -f docker-compose.prod.yml exec db \
    pg_restore -U peluqueria -d restore_test /backups/peluqueria_<fecha>.dump
  docker compose -f docker-compose.prod.yml exec db dropdb -U peluqueria restore_test
  ```

- Restauración real (¡destruye lo actual!): igual pero sobre `peluqueria` con
  `pg_restore --clean --if-exists`.
- Copia `./backups` fuera del host (rsync/objeto) si el servidor no es redundante.

## Salud y logs

- Health check: `https://<DOMAIN>/api/v1/health` (comprueba BD; el contenedor
  backend también lo usa como HEALTHCHECK).
- Logs: `docker compose -f docker-compose.prod.yml logs -f backend scheduler backup`.
