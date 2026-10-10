# Installation – production

← [Documentation](README.md)

Ce tutoriel met le serveur en ligne sur `https://oauth.mydomain.com` (remplacez par votre domaine partout).
Compter environ 30 minutes.

```
Internet ──HTTPS──▶ Caddy (sur le serveur, certificat Let's Encrypt automatique)
                      │  127.0.0.1:8080
                      ▼
                 docker compose : php (FrankenPHP) + database (PostgreSQL 16)
```

## 1. Prérequis

| Élément | Détail |
|---|---|
| Serveur | Linux (Debian 12/13, Ubuntu 22.04+…), 1 vCPU et 1 Go de RAM suffisent, 10 Go de disque |
| DNS | Un enregistrement `A` (et `AAAA` si IPv6) `oauth.mydomain.com` → IP du serveur |
| Pare-feu | Ports **80** et **443** ouverts (Caddy en a besoin pour obtenir le certificat) ; **8080 fermé** |
| Logiciels | Docker Engine + Compose v2, Caddy, git, make |
| E-mails | Un compte [Brevo](https://www.brevo.com) (gratuit jusqu'à 300 e-mails/jour) |

### Installer les logiciels

- Docker : <https://docs.docker.com/engine/install/> (puis `docker compose version` pour vérifier).
- Caddy : <https://caddyserver.com/docs/install> (paquet officiel, service systemd inclus).
- `sudo apt install git make`

### Préparer Brevo (envoi des e-mails)

1. **Expéditeurs, domaines** → ajoutez et **authentifiez votre domaine** (enregistrements DNS SPF, DKIM et DMARC
   fournis par Brevo) : sans cela, les codes de connexion risquent d'arriver en spam.
2. Ajoutez l'expéditeur, par exemple `no-reply@mydomain.com`.
3. **SMTP & API → Clés API** → créez une clé (elle commence par `xkeysib-`).

## 2. Récupérer le projet

```bash
sudo mkdir -p /srv/oauth && sudo chown $USER /srv/oauth
git clone <ce dépôt> /srv/oauth
cd /srv/oauth
```

## 3. Configurer

```bash
cp .env.docker.dist .env.docker
chmod 600 .env.docker
```

Générez les secrets, puis éditez `.env.docker` (`nano .env.docker`) :

```bash
openssl rand -hex 32    # à exécuter une fois pour chaque secret
```

| Variable | Valeur |
|---|---|
| `POSTGRES_PASSWORD` | un secret généré |
| `APP_SECRET` | un secret généré (**32 caractères minimum**, sinon le conteneur refuse de démarrer) |
| `OAUTH_PASSPHRASE` | un secret généré (protège la clé privée RSA) |
| `OAUTH_ENCRYPTION_KEY` | un secret généré (chiffre les codes et refresh tokens) |
| `DEFAULT_URI` | `https://oauth.mydomain.com` : URL publique exacte, sans `/` final. Sert aux liens des e-mails et d'émetteur (`iss`) des logout tokens : les applications le vérifient |
| `SITE_NAME` | nom affiché, ex. `"Mon compte"` |
| `MAILER_DSN` | `brevo+api://VOTRE_CLE_API@default` (ou en SMTP : `brevo+smtp://LOGIN_SMTP:CLE_SMTP@default`) |
| `MAILER_FROM_EMAIL` / `MAILER_FROM_NAME` | l'expéditeur validé dans Brevo |
| `HTTP_PORT` | port local du conteneur (8080 par défaut ; à changer s'il est déjà pris) |
| `OAUTH_ACCESS_TOKEN_TTL` | durée des access tokens (`PT15M` = 15 min) |
| `MFA_ENABLED` / `MFA_TRUSTED_DEVICE_LIFETIME` | MFA activée (`1`) / 30 jours de confiance (`2592000` s) |

> **Gardez une copie de `.env.docker` en lieu sûr** (gestionnaire de mots de passe) : `OAUTH_PASSPHRASE` et
> `OAUTH_ENCRYPTION_KEY` sont nécessaires pour restaurer une sauvegarde.

## 4. Construire et démarrer

```bash
make prod-build
make prod-up
make prod-ps        # php et database doivent être « healthy »
```

Au premier démarrage, le conteneur vérifie les secrets, génère la paire de clés RSA (volume `oauth_keys`), attend
PostgreSQL et applique les migrations. En cas de problème : `make prod-logs`.

Vérification locale : `curl -sI http://127.0.0.1:8080/login | head -1` → `HTTP/1.1 200 OK`.

## 5. HTTPS avec Caddy

```bash
sudo cp deploy/caddy/Caddyfile /etc/caddy/Caddyfile     # ou ajoutez le bloc à votre Caddyfile existant
sudo nano /etc/caddy/Caddyfile                           # remplacez oauth.mydomain.com (et 8080 si besoin)
sudo mkdir -p /var/log/caddy
sudo systemctl reload caddy
```

Caddy obtient le certificat, redirige HTTP → HTTPS, ajoute HSTS et transmet l'adresse IP réelle des visiteurs
(prise en compte grâce à `TRUSTED_PROXIES=private_ranges`). Ses journaux d'accès masquent les jetons et secrets.

Ouvrez <https://oauth.mydomain.com> : la page de connexion s'affiche, avec le cadenas.

> Caddy tourne lui aussi dans Docker ? Voir la variante en fin de `deploy/caddy/Caddyfile`.

## 6. Premier administrateur et vérifications

```bash
make prod-admin email=moi@mydomain.com          # mot de passe demandé
make prod-mailtest to=moi@mydomain.com          # e-mail de test via Brevo
```

Connectez-vous sur <https://oauth.mydomain.com> (le code arrive par e-mail), puis ouvrez `/admin`.

Contrôle de la configuration HTTPS et des en-têtes :
<https://securityheaders.com/?q=oauth.mydomain.com> et <https://www.ssllabs.com/ssltest/>.

Étape suivante : [déclarer vos applications](administration.md#déclarer-une-application).

## 7. Sauvegardes

```bash
make prod-backup
```

Crée dans `backups/` un dump PostgreSQL et une archive des **clés RSA** et des **logos**. Les trois sont
nécessaires : sans les clés, les jetons en cours deviennent invalides (les utilisateurs devront se reconnecter).

Planifiez-la avec la purge des jetons expirés (`crontab -e`) :

```cron
0 3 * * *  cd /srv/oauth && make prod-backup > /dev/null
30 3 * * * cd /srv/oauth && make prod-purge-tokens > /dev/null
0 4 * * *  find /srv/oauth/backups -mtime +30 -delete
```

Copiez régulièrement `backups/` **hors du serveur** (rsync, restic, stockage S3…).

**Restaurer** (testez-le au moins une fois) :

```bash
make prod-restore db=backups/db-20260101-030000.dump files=backups/files-20260101-030000.tar.gz
make prod-restart
```

## 8. Mises à jour

```bash
cd /srv/oauth
make prod-backup
make prod-deploy        # git pull, reconstruction de l'image, redémarrage ; migrations appliquées au démarrage
```

L'interruption dure quelques secondes (le temps de redémarrer le conteneur). L'image de base et les dépendances
sont mises à jour par des pull requests Dependabot hebdomadaires sur le dépôt.

## 9. Exploitation au quotidien

| Commande | Effet |
|---|---|
| `make prod-ps` | État des conteneurs |
| `make prod-logs` | Journaux en continu |
| `make prod-console c="…"` | Commande Symfony (ex. `c="app:user:revoke alice@mydomain.com"`) |
| `make prod-sh` | Shell dans le conteneur |
| `make prod-restart` | Redémarrer |
| `make prod-down` / `make prod-up` | Arrêter / démarrer |

Les journaux Docker sont limités en taille (rotation automatique). La gestion des utilisateurs et des
applications est décrite dans [Administration](administration.md).

## 10. Liste de contrôle sécurité

- [ ] `.env.docker` en `chmod 600`, sauvegardé hors du serveur, jamais versionné.
- [ ] Le port 8080 n'est **pas** joignable depuis Internet (`HTTP_BIND=127.0.0.1`, pare-feu).
- [ ] Domaine authentifié dans Brevo (SPF, DKIM, DMARC).
- [ ] Sauvegardes planifiées, copiées hors du serveur, restauration testée.
- [ ] Mises à jour du système (`unattended-upgrades`) et de l'application (`make prod-deploy`) régulières.
- [ ] Accès SSH par clé uniquement.

## À propos de l'image Docker

L'image de production (≈ 275 Mo, contre 835 Mo pour l'image FrankenPHP de départ) est construite en plusieurs étapes : les dépendances et le cache Symfony sont
préparés dans une étape intermédiaire, puis seuls PHP, FrankenPHP et l'application sont copiés dans l'image finale,
**sans compilateurs ni outils** (Composer, PECL, Perl…). Le code y est en lecture seule ; seuls `var/`, les clés et
les logos sont modifiables, par un utilisateur non-root. FrankenPHP tourne en **mode worker** : l'application reste
chargée en mémoire et répond en quelques millisecondes.

| Étape du `Dockerfile` | Rôle |
|---|---|
| `frankenphp_base` | FrankenPHP + extensions PHP (intl, pdo_pgsql, APCu, OPcache) |
| `frankenphp_build` | `composer install --no-dev`, autoload optimisé, `.env` compilé, cache préchauffé |
| `frankenphp_system` | la base débarrassée des compilateurs et outils de construction |
| `frankenphp_prod` | image finale : système + application |

## Installation sans Docker

Possible sur un serveur PHP classique : PHP 8.4 (extensions `intl`, `pdo_pgsql`, `apcu`), PostgreSQL 16 et un
serveur web (Caddy, Nginx + PHP-FPM) dont la racine est `public/`.

```bash
composer install --no-dev --classmap-authoritative
# .env.local : APP_ENV=prod, APP_SECRET, DATABASE_URL, DEFAULT_URI, OAUTH_PASSPHRASE, OAUTH_ENCRYPTION_KEY, MAILER_*
composer dump-env prod
php bin/console league:oauth2-server:generate-keypair
php bin/console doctrine:migrations:migrate
php bin/console app:user:create moi@mydomain.com --admin
```

Cron : `php bin/console league:oauth2-server:clear-expired-tokens` chaque nuit.

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| Le conteneur redémarre en boucle, « variables d'environnement manquantes » | Une variable obligatoire est vide dans `.env.docker` (`make prod-logs`). |
| « APP_SECRET doit faire au moins 32 caractères » | Régénérez-le avec `openssl rand -hex 32`. |
| « base de données injoignable » | `POSTGRES_PASSWORD` modifié après la création de la base : le mot de passe est fixé à la première initialisation du volume. Remettez l'ancien, ou changez-le dans PostgreSQL. |
| Caddy n'obtient pas de certificat | DNS pas encore propagé, ou ports 80/443 fermés : `journalctl -u caddy`. |
| Les codes de connexion n'arrivent pas | `make prod-mailtest to=…` ; vérifiez la clé API, l'expéditeur et les logs Brevo (*Transactionnel → Logs*). |
| Les liens des e-mails pointent vers `localhost` | `DEFAULT_URI` mal renseigné. |
| Les applications refusent les logout tokens (`iss` invalide) | `DEFAULT_URI` différent de l'URL du serveur configurée dans les applications. |
| « Échec du back-channel logout » dans `make prod-logs` | URL de l'application injoignable depuis le serveur, ou réponse ≠ 2xx : `make prod-console c="app:application:test-backchannel-logout <app> <email>"`. Le conteneur doit pouvoir joindre l'application (URL publique en HTTPS, ou réseau Docker partagé). |
| Les adresses IP des journaux sont toutes celles de Docker | `TRUSTED_PROXIES` doit valoir `private_ranges`. |
| `502 Bad Gateway` | Le conteneur est arrêté ou en démarrage : `make prod-ps`, `make prod-logs`. |
