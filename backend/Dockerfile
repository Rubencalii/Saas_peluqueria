# Imagen de PRODUCCIÓN del backend (Symfony + Apache).
# Build:  docker build -t peluqueria-backend ./backend
# La configuración llega por variables de entorno (ver .env.prod.example).

# ---- Etapa 1: dependencias con Composer -------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---- Etapa 2: runtime Apache + PHP ------------------------------------------
FROM php:8.5-apache

# pdo_pgsql (Postgres). php.ini de producción. (En PHP 8.5 opcache ya está
# integrado y activo; solo se ajusta por ini más abajo.)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Opcache agresivo: el código no cambia dentro del contenedor.
RUN { \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
    } > "$PHP_INI_DIR/conf.d/opcache-prod.ini"

# DocumentRoot en public/ y front-controller de Symfony como fallback.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n  AllowOverride None\n  Require all granted\n  FallbackResource /index.php\n</Directory>\n' \
       > /etc/apache2/conf-available/symfony.conf \
    && a2enconf symfony \
    && a2enmod headers

WORKDIR /var/www/html
COPY --from=vendor /app ./
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var

ENV APP_ENV=prod APP_DEBUG=0

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD php -r 'exit(str_contains((string) @file_get_contents("http://127.0.0.1/api/v1/health"), "\"ok\"") ? 0 : 1);'
