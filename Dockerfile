# syntax=docker/dockerfile:1
#
# Image FrankenPHP (Caddy + PHP 8.4 ZTS) multi-étapes :
#
#   frankenphp_upstream ─▶ frankenphp_base ─┬─▶ frankenphp_dev     développement (outils, Xdebug, code monté)
#                                           ├─▶ frankenphp_build   construit l'application (Composer, cache)
#                                           └─▶ frankenphp_system  PHP + FrankenPHP sans compilateurs ni outils
#                                                       │
#   scratch ◀── système aplati ─────────────────────────┘
#      └─▶ frankenphp_prod : système + application (code en lecture seule, utilisateur non-root, mode worker)
#
# Construction : make build (dev) / make prod-build (prod)

ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.4
# Version de Debian figée : une nouvelle version majeure ne doit pas arriver par surprise lors d'un build
ARG DEBIAN_VERSION=trixie

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-${DEBIAN_VERSION} AS frankenphp_upstream

# ---------------------------------------------------------------------------
# Base commune : extensions, utilisateur, configuration
# ---------------------------------------------------------------------------
FROM frankenphp_upstream AS frankenphp_base

# UID/GID de l'utilisateur applicatif (en dev, alignés sur l'utilisateur de la machine hôte)
ARG APP_UID=1000
ARG APP_GID=1000

WORKDIR /app

# Extensions PHP : PostgreSQL, intl (formats de dates fr), APCu (cache système Symfony), zip (Composer)
RUN install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        zip

# Utilisateur non-root
RUN groupadd --gid "${APP_GID}" app \
    && useradd --uid "${APP_UID}" --gid app --create-home --shell /bin/sh app \
    && mkdir -p /data/caddy /config/caddy /app/config/jwt /app/public/uploads/logos \
    # (les volumes nommés héritent de ces propriétaires à leur création)
    && chown -R app:app /data /config /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d" \
    # Port HTTP interne du conteneur (pas de HTTPS ici : il est géré par le Caddy de l'hôte)
    SERVER_NAME=":8080"

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 8080

HEALTHCHECK --start-period=60s --interval=30s --timeout=5s \
    CMD curl -fsS http://localhost:2019/metrics > /dev/null || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------------------
# Développement
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_dev

# Pas de mode worker en dev : chaque requête relit le code, les modifications sont visibles immédiatement
ENV APP_ENV=dev \
    XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions xdebug

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

USER app

# --watch : recharge Caddy si le Caddyfile monté change
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

# ---------------------------------------------------------------------------
# Construction de l'application (étape intermédiaire, absente de l'image finale)
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_build

ENV APP_ENV=prod \
    APP_DEBUG=0

# 1) Dépendances seules (couche mise en cache tant que composer.lock ne change pas)
COPY --link composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# 2) Code de l'application, autoload optimisé, .env compilé, cache préchauffé, assets
COPY --link . ./
RUN rm -rf frankenphp deploy tests \
    # (si Composer a dû cloner un paquet au lieu de télécharger l'archive)
    && find vendor -name .git -type d -prune -exec rm -rf {} + \
    && mkdir -p var/cache var/log var/share config/jwt public/uploads/logos \
    && composer dump-autoload --no-dev --classmap-authoritative \
    # Compile les .env en .env.local.php (les vraies variables d'environnement restent prioritaires)
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && rm -rf var/log/* \
    && chmod +x bin/console

# ---------------------------------------------------------------------------
# Système de production : la base, débarrassée de tout ce qui ne sert qu'à construire
# (l'image PHP officielle conserve gcc, g++, make… : ~370 Mo)
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_system

# Méthode de l'image PHP officielle : tous les paquets sont marqués « automatiques », puis seuls ceux dont
# PHP, FrankenPHP et les extensions ont réellement besoin (d'après ldd) sont conservés ; le reste
# (compilateurs, outils de construction, Perl…) est supprimé, quel que soit l'historique d'installation.
RUN set -eux; \
    apt-mark auto '.*' > /dev/null; \
    # ca-certificates (HTTPS sortant : Brevo), curl (healthcheck), media-types (/etc/mime.types pour Caddy)
    apt-mark manual ca-certificates curl media-types > /dev/null; \
    find /usr/local -type f \( -perm /0111 -o -name '*.so*' \) -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print "*" so }' \
        | sort -u | xargs -r dpkg-query --search 2>/dev/null | grep -v '^diversion' | cut -d: -f1 | sort -u \
        | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /var/cache/* /var/log/* /tmp/* /root/.composer \
        /usr/src/* /usr/local/include /usr/local/php /usr/local/lib/php/build /usr/local/lib/php/test \
        /usr/local/lib/php/doc /usr/local/lib/php/.registry /usr/local/lib/php/.channels \
        /usr/local/lib/php/PEAR* /usr/local/lib/php/Archive /usr/local/lib/php/Console /usr/local/lib/php/OS \
        /usr/local/lib/php/Structures /usr/local/lib/php/XML /usr/local/lib/php/pearcmd.php /usr/local/lib/php/peclcmd.php \
        /usr/local/lib/libwatcher-c.a \
        /usr/local/bin/composer /usr/local/bin/install-php-extensions /usr/local/bin/docker-php-ext-* \
        /usr/local/bin/docker-php-source /usr/local/bin/pear* /usr/local/bin/pecl /usr/local/bin/phar* \
        /usr/local/bin/php-cgi /usr/local/bin/phpdbg /usr/local/bin/phpize /usr/local/bin/php-config \
        /usr/share/doc/* /usr/share/man/* /usr/share/info/* /usr/share/lintian /app/public/index.php; \
    # Vérifications (le build échoue si l'une d'elles ne passe pas)
    if find /usr/local -type f \( -perm /0111 -o -name '*.so*' \) -exec ldd '{}' ';' 2>/dev/null | grep 'not found'; then \
        echo 'Bibliothèque manquante' >&2; exit 1; \
    fi; \
    for tool in gcc cc c++ cpp ld make; do \
        if command -v "$tool"; then echo "Outil de construction encore présent : $tool" >&2; exit 1; fi; \
    done; \
    # (perl-base, paquet essentiel de Debian utilisé par dpkg, reste ; le paquet perl complet, non)
    if dpkg -s perl > /dev/null 2>&1; then echo 'Paquet perl encore présent' >&2; exit 1; fi; \
    test -s /etc/mime.types; command -v curl; frankenphp version; php -m > /dev/null; \
    # Le binaire php (21 Mo) fait doublon avec PHP intégré à FrankenPHP : « php » devient un raccourci
    printf '#!/bin/sh\nexec frankenphp php-cli "$@"\n' > /usr/local/bin/php; \
    echo '<?php echo PHP_SAPI;' > /tmp/sapi.php; test "$(php /tmp/sapi.php)" = cli; rm /tmp/sapi.php

# ---------------------------------------------------------------------------
# Production : image finale aplatie (une couche système, une couche application)
# ---------------------------------------------------------------------------
FROM scratch AS frankenphp_prod

ARG APP_UID=1000
ARG APP_GID=1000

COPY --from=frankenphp_system / /

ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d" \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    GODEBUG=cgocheck=0 \
    SERVER_NAME=":8080" \
    APP_ENV=prod \
    APP_DEBUG=0 \
    # Mode worker : l'application Symfony reste chargée en mémoire entre les requêtes
    FRANKENPHP_CONFIG="import worker.Caddyfile"

WORKDIR /app

# Configuration PHP / Caddy de production
COPY --link frankenphp/conf.d/20-app.prod.ini /usr/local/etc/php/app.conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/frankenphp/worker.Caddyfile
RUN mv /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && rm /usr/local/etc/php/php.ini-development

# Code en lecture seule (root) ; seuls var/, les clés et les logos sont modifiables par l'utilisateur app
COPY --link --from=frankenphp_build /app /app
COPY --link --from=frankenphp_build --chown=${APP_UID}:${APP_GID} /app/var /app/var
COPY --link --from=frankenphp_build --chown=${APP_UID}:${APP_GID} /app/config/jwt /app/config/jwt
COPY --link --from=frankenphp_build --chown=${APP_UID}:${APP_GID} /app/public/uploads /app/public/uploads

EXPOSE 8080

HEALTHCHECK --start-period=60s --interval=30s --timeout=5s \
    CMD curl -fsS http://localhost:2019/metrics > /dev/null || exit 1

USER app

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
