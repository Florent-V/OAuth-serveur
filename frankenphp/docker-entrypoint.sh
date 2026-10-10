#!/bin/sh
# Préparation au démarrage du conteneur : dépendances (dev), clés OAuth2, base de données, migrations.
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

    # --- Dev : installe les dépendances si le dossier vendor est absent (code monté depuis l'hôte)
    if [ "$APP_ENV" != 'prod' ] && [ ! -f vendor/autoload_runtime.php ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    # --- Prod : vérifie les secrets indispensables avant de démarrer
    if [ "$APP_ENV" = 'prod' ]; then
        missing=''
        for var in APP_SECRET OAUTH_PASSPHRASE OAUTH_ENCRYPTION_KEY MAILER_DSN MAILER_FROM_EMAIL; do
            eval "value=\${$var:-}"
            [ -z "$value" ] && missing="$missing $var"
        done
        if [ -n "$missing" ]; then
            echo "ERREUR : variables d'environnement manquantes :$missing (voir .env.docker.dist)" >&2
            exit 1
        fi
        if [ "${#APP_SECRET}" -lt 32 ]; then
            echo "ERREUR : APP_SECRET doit faire au moins 32 caractères (openssl rand -hex 32)" >&2
            exit 1
        fi
    fi

    # --- Clés RSA de signature des jetons : générées une seule fois, conservées dans un volume
    if [ ! -f config/jwt/private.pem ]; then
        echo "Génération de la paire de clés OAuth2..."
        php bin/console league:oauth2-server:generate-keypair --skip-if-exists --no-interaction
    fi
    chmod 600 config/jwt/private.pem 2>/dev/null || true

    # --- Attente de la base de données (60 s max)
    if grep -q ^DATABASE_URL= .env 2>/dev/null || [ -n "$DATABASE_URL" ]; then
        echo "Attente de la base de données..."
        attempts=60
        until [ $attempts -eq 0 ] || php bin/console dbal:run-sql -q "SELECT 1" > /dev/null 2>&1; do
            attempts=$((attempts - 1))
            sleep 1
        done
        if [ $attempts -eq 0 ]; then
            echo "ERREUR : base de données injoignable." >&2
            php bin/console dbal:run-sql "SELECT 1" || true
            exit 1
        fi

        # --- Migrations
        if [ "$( find ./migrations -iname '*.php' -print -quit )" ]; then
            php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
        fi
    fi

    echo "Serveur prêt."
fi

exec docker-php-entrypoint "$@"
