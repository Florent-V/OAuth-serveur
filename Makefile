# Commandes utiles en développement et en production.  Aide : make help
#
# Les variables (HTTP_PORT, MAILPIT_PORT, DB_PORT, XDEBUG_MODE...) peuvent être passées en ligne
# de commande (make up HTTP_PORT=8000) ou définies dans .env.local (HTTP_PORT=8000).

-include .env.local
export

HTTP_PORT    ?= 8080
MAILPIT_PORT ?= 8025
DB_PORT      ?= 5432
APP_UID      ?= $(shell id -u)
APP_GID      ?= $(shell id -g)

DOCKER_COMP  = docker compose
# Pas de pseudo-terminal quand make ne tourne pas dans un terminal (CI, cron, redirection)
TTY          := $(shell [ -t 0 ] || echo -T)
PHP_CONT     = $(DOCKER_COMP) exec $(TTY) php
CONSOLE      = $(PHP_CONT) php bin/console

# Production : fichiers compose + variables dans .env.docker
PROD_ENV     = .env.docker
PROD_COMP    = docker compose --env-file $(PROD_ENV) -f compose.yaml -f compose.prod.yaml
PROD_CONSOLE = $(PROD_COMP) exec $(TTY) php php bin/console
BACKUP_DIR   = backups

.DEFAULT_GOAL := help
.PHONY: help build up start down restart logs sh bash composer console cc migrate migration \
        test test-pg admin grant revoke db mails public-key fix-perms \
        prod-check prod-build prod-up prod-down prod-restart prod-logs prod-ps prod-sh prod-console \
        prod-migrate prod-admin prod-app prod-grant prod-revoke prod-mailtest prod-public-key prod-purge-tokens \
        prod-backup prod-restore prod-deploy

## —— Aide ——————————————————————————————————————————————————————————————
help: ## Affiche cette aide
	@grep -hE '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^## )' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-20s\033[0m %s\n", $$1, $$2}' \
		| sed -e 's/\[32m## /\n[33m/' -e 's/\[32m\(.*\)\[0m \(.*\)/[32m\1[0m \2/'

## —— Développement ————————————————————————————————————————————————————
build: ## Construit l'image de dev
	@$(DOCKER_COMP) build --pull

up: ## Démarre l'environnement de dev (http://localhost:HTTP_PORT, e-mails sur http://localhost:MAILPIT_PORT)
	@$(DOCKER_COMP) up --detach --wait
	@echo "Application : http://localhost:$(HTTP_PORT)   E-mails (Mailpit) : http://localhost:$(MAILPIT_PORT)"

start: build up ## Construit puis démarre

down: ## Arrête l'environnement de dev
	@$(DOCKER_COMP) down --remove-orphans

restart: down up ## Redémarre

logs: ## Journaux en continu
	@$(DOCKER_COMP) logs --tail=100 --follow

sh: ## Shell dans le conteneur PHP
	@$(PHP_CONT) sh

composer: ## Composer, ex. make composer c="require symfony/foo"
	@$(PHP_CONT) composer $(c)

console: ## Console Symfony, ex. make console c="debug:router"
	@$(CONSOLE) $(c)

cc: ## Vide le cache
	@$(CONSOLE) cache:clear

migrate: ## Applique les migrations
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction

migration: ## Génère une migration à partir des entités
	@$(CONSOLE) doctrine:migrations:diff

test: ## Lance les tests (SQLite), ex. make test c="--filter MfaTest"
	@$(PHP_CONT) php bin/phpunit $(c)

test-pg: ## Lance les tests sur la base PostgreSQL du conteneur (base app_test)
	@$(DOCKER_COMP) exec database sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -tc "SELECT 1 FROM pg_database WHERE datname = '"'"'app_test'"'"'" | grep -q 1 || createdb -U "$$POSTGRES_USER" app_test'
	@$(DOCKER_COMP) exec -e DATABASE_URL="postgresql://$${POSTGRES_USER:-app}:$${POSTGRES_PASSWORD:-!ChangeMe!}@database:5432/app_test?serverVersion=16&charset=utf8" php php bin/phpunit $(c)

admin: ## Crée un administrateur, ex. make admin email=moi@example.com
	@$(CONSOLE) app:user:create $(email) --admin

grant: ## Donne l'accès à une application, ex. make grant email=... app=app1
	@$(CONSOLE) app:access:grant $(email) $(app)

revoke: ## Bloque un utilisateur, ex. make revoke email=...
	@$(CONSOLE) app:user:revoke $(email)

public-key: ## Affiche la clé publique de signature des JWT (pour les applications)
	@$(DOCKER_COMP) exec -T php cat config/jwt/public.pem

db: ## Client psql sur la base de dev
	@$(DOCKER_COMP) exec database sh -c 'psql -U "$$POSTGRES_USER" "$$POSTGRES_DB"'

mails: ## Ouvre l'adresse de Mailpit (e-mails capturés en dev)
	@echo "http://localhost:$(MAILPIT_PORT)"

fix-perms: ## Rend à l'utilisateur courant les fichiers créés par le conteneur
	@$(DOCKER_COMP) run --rm --no-deps --user root --entrypoint sh php -c 'chown -R $(APP_UID):$(APP_GID) /app/var /app/vendor'

## —— Production ———————————————————————————————————————————————————————
prod-check: ## Vérifie que .env.docker existe
	@test -f $(PROD_ENV) || (echo "Créez $(PROD_ENV) : cp .env.docker.dist $(PROD_ENV) puis remplissez-le." && exit 1)

prod-build: prod-check ## Construit l'image de production
	@$(PROD_COMP) build --pull

prod-up: prod-check ## Démarre (ou met à jour) la production
	@$(PROD_COMP) up --detach --wait

prod-deploy: prod-check ## Déploie : git pull, build, redémarrage (migrations appliquées au démarrage)
	@git pull --ff-only
	@$(MAKE) --no-print-directory prod-build prod-up
	@$(PROD_COMP) ps

prod-down: prod-check ## Arrête la production
	@$(PROD_COMP) down

prod-restart: prod-check ## Redémarre la production
	@$(PROD_COMP) restart

prod-logs: prod-check ## Journaux de production
	@$(PROD_COMP) logs --tail=200 --follow

prod-ps: prod-check ## État des conteneurs
	@$(PROD_COMP) ps

prod-sh: prod-check ## Shell dans le conteneur de production
	@$(PROD_COMP) exec php sh

prod-console: prod-check ## Console Symfony en production, ex. make prod-console c="app:user:create ..."
	@$(PROD_CONSOLE) $(c)

prod-migrate: prod-check ## Applique les migrations en production
	@$(PROD_CONSOLE) doctrine:migrations:migrate --no-interaction

prod-admin: prod-check ## Crée un administrateur, ex. make prod-admin email=moi@mydomain.com
	@$(PROD_CONSOLE) app:user:create $(email) --admin

prod-app: prod-check ## Déclare une application, ex. make prod-app name="App 1" id=app1 url=https://app1.mydomain.com
	@$(PROD_CONSOLE) app:application:create "$(name)" --id=$(id) --home-url=$(url)/ --redirect-uri=$(url)/auth/callback \
		--backchannel-logout-uri=$(url)/auth/backchannel-logout

prod-grant: prod-check ## Donne l'accès, ex. make prod-grant email=... app=app1
	@$(PROD_CONSOLE) app:access:grant $(email) $(app)

prod-revoke: prod-check ## Bloque un utilisateur et le déconnecte partout, ex. make prod-revoke email=...
	@$(PROD_CONSOLE) app:user:revoke $(email)

prod-mailtest: prod-check ## Envoie un e-mail de test via Brevo, ex. make prod-mailtest to=moi@mydomain.com
	@$(PROD_CONSOLE) mailer:test $(to)

prod-public-key: prod-check ## Clé publique de signature des JWT, ex. make prod-public-key > oauth-public.pem
	@$(PROD_COMP) exec -T php cat config/jwt/public.pem

prod-purge-tokens: prod-check ## Supprime les jetons expirés (à planifier en cron)
	@$(PROD_COMP) exec -T php php bin/console league:oauth2-server:clear-expired-tokens

prod-backup: prod-check ## Sauvegarde base + clés RSA + logos dans backups/
	@mkdir -p $(BACKUP_DIR)
	@$(PROD_COMP) exec -T database sh -c 'pg_dump -U "$$POSTGRES_USER" -Fc "$$POSTGRES_DB"' > $(BACKUP_DIR)/db-$$(date +%Y%m%d-%H%M%S).dump
	@$(PROD_COMP) exec -T php tar -czf - -C /app config/jwt public/uploads > $(BACKUP_DIR)/files-$$(date +%Y%m%d-%H%M%S).tar.gz
	@ls -lh $(BACKUP_DIR) | tail -n 4

prod-restore: prod-check ## Restaure une sauvegarde, ex. make prod-restore db=backups/db-X.dump files=backups/files-X.tar.gz
	@test -n "$(db)" || (echo "Paramètre db=... requis" && exit 1)
	@$(PROD_COMP) exec -T database sh -c 'pg_restore -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" --clean --if-exists' < $(db)
	@if [ -n "$(files)" ]; then $(PROD_COMP) exec -T php tar -xzf - -C /app < $(files); fi
	@echo "Restauration terminée."
