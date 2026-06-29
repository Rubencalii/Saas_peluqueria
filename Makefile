# Atajos del proyecto. Uso: `make <objetivo>`.
.PHONY: help secret check-prod test test-front build-front

help:
	@echo "secret       Genera un APP_SECRET aleatorio (>=32 bytes)"
	@echo "check-prod   Valida las variables de entorno de produccion (backend/bin/check-prod.php)"
	@echo "test         Tests del backend (PHPUnit)"
	@echo "test-front   Tests del frontend (Vitest)"
	@echo "build-front  Build de produccion del frontend"

# Genera un secreto fuerte para APP_SECRET / claves.
secret:
	@php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# Valida la config de prod. Ejecutar con las variables de entorno reales, p.ej.:
#   APP_ENV=prod APP_SECRET=... CORS_ALLOWED_ORIGINS=... make check-prod
check-prod:
	@php backend/bin/check-prod.php

test:
	@cd backend && php bin/phpunit

test-front:
	@cd frontend && npm run test

build-front:
	@cd frontend && npm run build
