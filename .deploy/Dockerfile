# syntax=docker/dockerfile:1
#
# Build context = RAIZ do repo (definido no compose: context: ..).
# Imagem unica usada por web/horizon/reverb/scheduler.

# ---------------------------------------------------------------------------
# Stage 1 — vendor PHP (composer). Sai como artefato reusado pelos stages de
# assets E final. Necessario no build de assets porque o CSS importa
# vendor/tallstackui/... e varre vendor/ via @source.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
# --ignore-platform-reqs: a imagem composer:2 nao tem ext-pcntl/pgsql/etc, mas
# quem precisa delas é a imagem final (FrankenPHP), que as instala. Aqui so
# baixamos os pacotes; o check de plataforma seria um falso negativo.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2 — assets (Vite). VITE_* sao baked aqui: precisam ser os valores de
# PRODUCAO (build args, vindos da aba Environment do Dokploy). Sao publicos
# por natureza (key do Reverb + host ws), nao ha segredo aqui.
# Debian (glibc), nao alpine: o package.json fixa binarios nativos
# linux-x64-gnu (rollup, lightningcss, oxide) que nao rodam em musl.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

ARG VITE_APP_NAME=Jarvis
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# Codigo + vendor: o CSS importa vendor/tallstackui/ e o Tailwind varre
# app/ e vendor/ via @source. Sem isso o build do CSS quebra (Can't resolve
# vendor/...) ou sai sem as classes dos componentes.
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — imagem final (FrankenPHP). Pinada em versao especifica.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1.13.2-php8.5 AS app

# Extensions exigidas pelo composer.lock + curl p/ healthcheck:
# pdo_pgsql/pgsql (PostgreSQL), redis (fila/cache), pcntl+posix (Horizon),
# intl (Carbon/locale), zip (TallStackUI), opcache.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        pcntl \
        posix \
        intl \
        zip \
        opcache

COPY .deploy/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

# FrankenPHP escuta em porta nao-privilegiada (8080) para poder rodar como
# usuario nao-root. Traefik termina o TLS e encaminha pra esta porta.
ENV SERVER_NAME=:8080
# Caddy/FrankenPHP precisa de dirs graváveis pelo usuario nao-root.
ENV XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Vendor pronto do stage `vendor` (evita reinstalar) + codigo da app.
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Assets buildados pelo stage `assets` (gera public/build/manifest.json que o
# Vite resolve em runtime). Sem isso: ViteManifestNotFoundException.
COPY --from=assets /app/public/build ./public/build

# Autoloader otimizado (ja com classes da app) + package discovery sem os
# pacotes dev + symlink do disco publico. Tudo em build, como root, deixando
# o codigo read-only em runtime (www-data nao reescreve a aplicacao).
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && ln -sf /app/storage/app/public /app/public/storage \
    && mkdir -p /data /config \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

# Roda como usuario sem privilegios.
USER www-data

COPY --chown=www-data:www-data .deploy/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
