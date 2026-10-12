# Liste de contrôle – mise en production

← [Documentation](README.md) · procédure détaillée : [Installation – production](installation-production.md)

À cocher dans l'ordre. Architecture : Caddy installé sur le serveur (HTTPS) → `127.0.0.1:8080` → conteneur
FrankenPHP + PostgreSQL (docker compose). Remplacez `oauth.mydomain.com` par votre domaine.

## 1. Avant de déployer (poste de développement)

- [x] Tout le travail est commité et poussé sur `main` (migrations comprises).
- [x] La CI GitHub est verte (tests SQLite + PostgreSQL, image Docker, démarrage de la stack de prod).

## 2. DNS et e-mails

- [x] Enregistrement `A` (et `AAAA` si IPv6) `oauth.mydomain.com` → IP du serveur, propagé (`dig +short oauth.mydomain.com`).
- [x] Brevo : domaine authentifié (SPF, DKIM, DMARC).
- [x] Brevo : expéditeur validé (ex. `no-reply@mydomain.com`).
- [x] Brevo : clé API créée (`xkeysib-…`).

## 3. Serveur

- [x] Accès SSH par clé uniquement (`PasswordAuthentication no`).
- [x] Mises à jour de sécurité automatiques (`unattended-upgrades`).
- [x] Pare-feu : ports **80** et **443** ouverts, **8080 fermé** depuis Internet (`sudo ufw status`).
- [x] Docker Engine + Compose v2 installés (`docker compose version`).
- [x] Caddy installé (paquet officiel, service systemd actif : `systemctl status caddy`).
- [x] `git` et `make` installés.

## 4. Récupération et configuration

- [x] Projet cloné :
  ```bash
  sudo mkdir -p /srv/oauth && sudo chown $USER /srv/oauth
  git clone <ce dépôt> /srv/oauth && cd /srv/oauth
  ```
- [x] `cp .env.docker.dist .env.docker && chmod 600 .env.docker`
- [x] Quatre secrets générés avec `openssl rand -hex 32` : `POSTGRES_PASSWORD`, `APP_SECRET`, `OAUTH_PASSPHRASE`,
  `OAUTH_ENCRYPTION_KEY`.
- [x] `DEFAULT_URI=https://oauth.mydomain.com`, **sans `/` final** (émetteur `iss` vérifié par les applications).
- [x] `MAILER_DSN=brevo+api://CLE_API@default`, `MAILER_FROM_EMAIL`, `MAILER_FROM_NAME`, `SITE_NAME` renseignés.
- [x] `HTTP_BIND=127.0.0.1` et `TRUSTED_PROXIES=private_ranges` laissés tels quels.
- [x] **Copie de `.env.docker` dans un gestionnaire de mots de passe** : sans `OAUTH_PASSPHRASE` et
  `OAUTH_ENCRYPTION_KEY`, une sauvegarde ne peut pas être restaurée.

## 5. Démarrage

- [x] `make prod-build && make prod-up`
- [x] `make prod-ps` : `php` et `database` sont `healthy` (sinon `make prod-logs`).
- [x] `curl -sI http://127.0.0.1:8080/login | head -1` répond `200`.

## 6. HTTPS (Caddy de l'hôte)

- [x] Bloc ajouté à `/etc/caddy/Caddyfile` (domaine et port adaptés) :

  ```caddy
  oauth.mydomain.com {
  	encode zstd gzip

  	header {
  		# Retirer includeSubDomains si un sous-domaine sert encore du HTTP
  		Strict-Transport-Security "max-age=31536000; includeSubDomains"
  		-Server
  	}

  	# Conteneur FrankenPHP ; X-Forwarded-* transmis (TRUSTED_PROXIES=private_ranges côté Symfony)
  	reverse_proxy 127.0.0.1:8080 {
  		health_uri /login
  		health_interval 30s
  	}

  	log {
  		output file /var/log/caddy/oauth.access.log {
  			roll_size 20mb
  			roll_keep 10
  		}
  		# Pas de secret dans les journaux
  		format filter {
  			request>uri regexp "(/reset-password/reset/)[^?]+|([?&](?:code|code_verifier|client_secret)=)[^&]*" "${1}${2}REDACTED"
  			request>headers>Authorization delete
  			request>headers>Cookie delete
  		}
  	}
  }
  ```

- [x] `/var/log/caddy` existe et appartient à `caddy` (`sudo mkdir -p /var/log/caddy && sudo chown caddy:caddy /var/log/caddy`).
- [x] `sudo -u caddy caddy validate --config /etc/caddy/Caddyfile` sans erreur (en tant que `caddy`, pas root :
  sinon les fichiers de journaux sont créés par root et le `reload` échoue avec « permission denied »).
- [x] `sudo systemctl reload caddy`
- [x] <https://oauth.mydomain.com> s'affiche avec le cadenas (sinon `journalctl -u caddy`).

## 7. Premier administrateur et vérifications

- [x] `make prod-admin email=moi@mydomain.com`
- [x] `make prod-mailtest to=moi@mydomain.com` : e-mail reçu, **pas en spam**.
- [x] Connexion sur <https://oauth.mydomain.com> avec le code MFA reçu par e-mail, puis accès à `/admin`.
- [ ] Mot de passe oublié testé (lien de l'e-mail en `https://oauth.mydomain.com/...`, pas `localhost`).
- [x] En-têtes et TLS contrôlés : <https://securityheaders.com/?q=oauth.mydomain.com> et
  <https://www.ssllabs.com/ssltest/>.
- [x] Le port 8080 n'est pas joignable depuis l'extérieur (`curl -m 5 http://IP_DU_SERVEUR:8080` échoue).

## 8. Applications clientes

- [ ] Chaque application déclarée (`make prod-app name="App 1" id=app1 url=https://app1.mydomain.com` ou `/admin`).
- [ ] Accès attribués (`make prod-grant email=... app=app1`).
- [ ] Connexion complète testée depuis chaque application.
- [ ] Back-channel logout testé :
  `make prod-console c="app:application:test-backchannel-logout app1 moi@mydomain.com"`.

## 9. Sauvegardes et maintenance

- [ ] Tâches planifiées (`crontab -e`) :
  ```cron
  0 3 * * *  cd /srv/oauth && make prod-backup > /dev/null
  30 3 * * * cd /srv/oauth && make prod-purge-tokens > /dev/null
  0 4 * * *  find /srv/oauth/backups -mtime +30 -delete
  ```
- [ ] `backups/` copié régulièrement **hors du serveur** (restic, rsync, stockage S3…).
- [ ] **Restauration testée** au moins une fois :
  `make prod-restore db=backups/db-….dump files=backups/files-….tar.gz && make prod-restart`.
- [ ] Procédure de mise à jour connue : `make prod-backup && make prod-deploy`.
- [ ] Pull requests Dependabot suivies et fusionnées régulièrement.
