#!/bin/sh
set -e

# Caches de framework: rodam em todos os containers (idempotente, sem race).
# Vem antes do migrate para o boot do web ja ler a config cacheada.
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Passos exclusivos do container web (gate RUN_MIGRATIONS=true, setado so no
# servico `web`), evitando 4 containers migrando ao mesmo tempo. horizon,
# reverb e scheduler so sobem depois que o web fica healthy (depends_on).
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate --force
    # Lookups do sistema (upsert pelo slug) ANTES do provision: os canais de
    # notificacao sao lookups e o provision falha sem eles.
    php artisan db:seed --class=LookupSeeder --force
    # Tipos de notificacao + templates padrao (idempotente, nunca sobrescreve
    # template editado).
    php artisan notifications:provision
    php artisan view:cache
fi

exec "$@"
