# Installation – développement

← [Documentation](README.md)

Ce tutoriel installe le serveur sur votre poste, avec une base PostgreSQL et un faux serveur d'e-mails (Mailpit).

## 1. Prérequis

- **Docker** avec **Compose v2** (`docker compose version` doit répondre) :
  Docker Desktop (Windows, macOS) ou Docker Engine (Linux).
- **make** (préinstallé sous Linux et macOS ; sous Windows, utilisez WSL 2).
- **git**.

Rien d'autre : PHP, Composer et PostgreSQL tournent dans les conteneurs.

## 2. Récupérer le projet et démarrer

```bash
git clone <ce dépôt> oauth
cd oauth
make start
```

`make start` construit l'image de développement puis démarre trois conteneurs :

| Conteneur | Rôle | Adresse |
|---|---|---|
| `php` | le serveur (FrankenPHP) | <http://localhost:8080> |
| `database` | PostgreSQL 16 | `localhost:5432` (utilisateur `app`, mot de passe `!ChangeMe!`) |
| `mailer` | Mailpit : capture tous les e-mails envoyés | <http://localhost:8025> |

Au premier démarrage, le conteneur installe les dépendances Composer, génère les clés RSA de signature et applique
les migrations. Suivez la progression avec `make logs` (Ctrl+C pour quitter) ; c'est prêt quand vous voyez
« Serveur prêt ».

> **Port déjà utilisé ?** Changez-le : `make up HTTP_PORT=8000`, ou ajoutez `HTTP_PORT=8000` dans un fichier
> `.env.local` à la racine (lu par le Makefile). Même chose pour `MAILPIT_PORT` (8025) et `DB_PORT` (5432).

## 3. Créer votre compte administrateur

```bash
make admin email=moi@example.com
```

Le mot de passe est demandé (10 caractères minimum). Ouvrez <http://localhost:8080>, connectez-vous : un code à
6 chiffres vous est « envoyé » — il arrive dans **Mailpit** (<http://localhost:8025>). Saisissez-le : vous voilà sur
le portail. L'administration est sur <http://localhost:8080/admin>.

## 4. Connecter une application

Déclarez une application dans `/admin` → **Applications** (ou `make console c="app:application:create ..."`),
donnez-vous l'accès, puis faites pointer votre application vers <http://localhost:8080> : voir
[Intégrer une application](integrer-une-application.md).

## 5. Travailler sur le code

- Le code est **monté** dans le conteneur : toute modification (PHP, Twig, configuration) est visible au
  rechargement de la page, sans redémarrer.
- Les fichiers créés par le conteneur appartiennent à votre utilisateur (UID/GID transmis à la construction). En cas
  de souci de droits : `make fix-perms`.

| Commande | Effet |
|---|---|
| `make logs` | Journaux en continu |
| `make sh` | Shell dans le conteneur PHP |
| `make console c="debug:router"` | Commande Symfony |
| `make composer c="require vendor/paquet"` | Commande Composer |
| `make cc` | Vider le cache |
| `make migration` | Générer une migration après modification d'une entité |
| `make migrate` | Appliquer les migrations |
| `make db` | Client `psql` sur la base |
| `make test` / `make test-pg` | Tests (voir [Tests](tests.md)) |
| `make down` | Tout arrêter (les données sont conservées) |
| `make help` | Liste complète |

### Déboguer avec Xdebug

```bash
make up XDEBUG_MODE=debug
```

Xdebug se connecte à `host.docker.internal:9003` quand la requête porte le déclencheur (extension de navigateur
« Xdebug helper » ou paramètre `XDEBUG_TRIGGER=1`). Dans PhpStorm, associez le dossier du projet à `/app`.

### Repartir de zéro

```bash
make down
docker compose down -v      # supprime aussi la base, les clés et les logos
make start
```

## 6. Sans Docker (optionnel)

Avec PHP 8.4 (extensions `intl`, `pdo_sqlite`) et Composer installés localement :

```bash
composer install
php bin/console league:oauth2-server:generate-keypair   # clés RSA (phrase de passe : OAUTH_PASSPHRASE de .env.dev)
php bin/console doctrine:schema:create                  # base SQLite : var/data_dev.db
php bin/console app:user:create moi@example.com --admin
symfony serve        # ou : php -S 127.0.0.1:8000 -t public
```

En dev, `MAILER_DSN=null://null` : les e-mails ne partent pas. Pour lire les codes MFA, lancez Mailpit
(`docker run -p 8025:8025 -p 1025:1025 axllent/mailpit`) et mettez `MAILER_DSN=smtp://127.0.0.1:1025` dans
`.env.local`.

## Dépannage

| Problème | Solution |
|---|---|
| `port is already allocated` | Un autre programme utilise le port : `make up HTTP_PORT=8000` (voir étape 2). |
| La page ne répond pas juste après `make start` | Premier démarrage en cours : `make logs`, attendez « Serveur prêt ». |
| Je ne reçois pas le code de connexion | Il est dans Mailpit : <http://localhost:8025>. |
| « Trop de tentatives » | Limite de 5 essais / 15 min : attendez, ou `make console c="cache:pool:clear cache.rate_limiter"`. |
| `Permission denied` sur `var/` ou `vendor/` | `make fix-perms` |
| Erreur après un changement d'entité | `make migration` puis `make migrate`. |
