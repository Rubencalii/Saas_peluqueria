# 11 · Despliegue y DevOps

> Orientativo. El desarrollador puede ajustarlo a su proveedor y herramientas preferidas.

## 1. Entornos
- **Desarrollo (local)**: cada dev en su máquina; base de datos local o en contenedor.
- **Staging / pruebas**: réplica del entorno real para UAT y aprobación del cliente. Número de WhatsApp de pruebas.
- **Producción**: entorno real con datos reales.

Cada entorno con su propia configuración (variables de entorno), sin credenciales en el código.

## 2. Componentes a desplegar
- **Frontend** (web + panel): build estático/SSR servido por CDN o servidor Node.
- **Backend / API**: servicio en contenedor.
- **Worker de jobs**: proceso aparte para recordatorios y tareas programadas.
- **Base de datos**: PostgreSQL gestionado (recomendado un servicio administrado para backups y alta disponibilidad).
- **Cola/cache**: Redis (para jobs y, si se usa, holds temporales).

## 3. Contenedores y configuración
- Empaquetar backend y worker en **contenedores** (Docker).
- Configuración por **variables de entorno**: credenciales de BD, token de WhatsApp/Meta, claves de firma, etc.
- Nunca subir secretos al repositorio; usar un gestor de secretos del proveedor.

## 4. CI/CD
- **CI**: en cada push, ejecutar pruebas y linters automáticamente.
- **CD**: despliegue automático a staging al fusionar; a producción con aprobación manual.
- Migraciones de base de datos versionadas y aplicadas de forma controlada en cada despliegue.

## 5. Webhook de WhatsApp
- Endpoint público HTTPS estable para recibir mensajes de Meta.
- Verificación del webhook (verify token) configurada.
- Validar la **firma** de las peticiones de Meta para asegurar que vienen de ellos.

## 6. Copias de seguridad
- Backups **automáticos y diarios** de la base de datos, con retención definida.
- Probar la **restauración** periódicamente (un backup que no se sabe restaurar no sirve).
- Considerar point-in-time recovery si el proveedor lo ofrece.

## 7. Monitorización y alertas
- **Disponibilidad (uptime)** de la web y la API.
- **Errores** (agregador de logs / seguimiento de errores).
- **Métricas**: latencia de la API, tasa de errores, fallos de envío de WhatsApp.
- **Alertas** ante caída del webhook de WhatsApp o del worker de recordatorios (si falla, no salen avisos y suben los no-shows).

## 8. Seguridad operativa
- HTTPS forzado en todo.
- Certificados gestionados/renovados automáticamente (incluye dominios propios por sede del doc 08).
- Rate limiting y protección contra abuso en endpoints públicos.
- Principio de mínimo privilegio en accesos a infraestructura.
- Rotación de credenciales y tokens.

## 9. Escalado
- Backend y worker **sin estado** → escalan horizontalmente añadiendo instancias.
- Base de datos: empezar con una instancia gestionada; escalar verticalmente o con réplicas de lectura si el volumen crece.
- El diseño multi-sede ya contempla crecer en número de sedes sin rehacer la arquitectura.

## 10. Checklist de puesta en producción
- [ ] Variables de entorno de producción configuradas.
- [ ] Migraciones aplicadas.
- [ ] Webhook de WhatsApp verificado y con firma validada.
- [ ] Plantillas de WhatsApp aprobadas (doc 07).
- [ ] Backups automáticos activos y restauración probada.
- [ ] Monitorización y alertas activas.
- [ ] HTTPS y certificados OK (incluidos dominios por sede).
- [ ] Prueba E2E en producción con una cita real de prueba.
