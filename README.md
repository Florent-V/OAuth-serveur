# Serveur OAuth2 (SSO) – Symfony

Serveur d'authentification centralisé pour vos applications auto-hébergées :

```
oauth.mydomain.com   ← ce serveur : connexion, inscription, administration
app1.mydomain.com    ← vos applications, qui délèguent la connexion à oauth.mydomain.com
app2.mydomain.com
```

- **Un seul compte par personne**, commun à toutes les applications, avec **connexion unique** (SSO).
- **Double authentification** par code e-mail, avec appareils de confiance.
- **Contrôle d'accès par application** : l'administrateur décide qui peut utiliser quoi.
- **Révocation immédiate** : déconnecter partout, bloquer un compte, retirer un accès ; les applications sont
  prévenues en quelques secondes (**OpenID Connect Back-Channel Logout**).
- Pages de connexion **personnalisables** par application (logo, couleurs, message).
- Mot de passe oublié, inscription, portail des applications, administration web.
- OAuth 2.0 standard (*Authorization Code* + PKCE, *Refresh Token*), jetons JWT signés RS256.

## Documentation

| | |
|---|---|
| [Fonctionnement](docs/fonctionnement.md) | SSO, contrôle d'accès, jetons, MFA, sécurité, architecture |
| [Installation – développement](docs/installation-developpement.md) | Sur votre poste avec Docker, en 3 commandes |
| [Installation – production](docs/installation-production.md) | Serveur, HTTPS avec Caddy, e-mails Brevo, sauvegardes, mises à jour |
| [Administration](docs/administration.md) | Applications, utilisateurs, accès, personnalisation, révocation |
| [Architecture d'une application cliente](docs/architecture-application-cliente.md) | Les règles à respecter dans toute application protégée (BFF, jetons, révocation) |
| [**Adapter une application**](docs/adapter-une-application.md) | Protéger une application existante : Nuxt SSR, Nuxt + Python, HTML/JS + Python, cas général |
| [Intégrer une application](docs/integrer-une-application.md) | Référence du protocole : paramètres, requêtes, back-channel logout, erreurs, exemples (PHP, Symfony, Node.js, Grafana…) |
| [Tests](docs/tests.md) | Tests fonctionnels, intégration continue |
| [Sécurité GitHub](docs/securite-github.md) | Protection de main, Dependabot, audit des dépendances et CodeQL (workflows Actions uniquement, pas PHP) |
| [Feuille de route](ROADMAP.md) | Prochaines fonctionnalités |

## Démarrage rapide

Prérequis : Docker (Compose v2) et `make`.

```bash
git clone <ce dépôt> oauth && cd oauth
make start                                    # serveur + PostgreSQL + Mailpit
make admin email=moi@example.com              # premier administrateur
```

| | |
|---|---|
| Serveur | <http://localhost:8080> (administration : `/admin`) |
| E-mails envoyés (codes de connexion) | <http://localhost:8025> |

Ports modifiables : `make up HTTP_PORT=8000`. Toutes les commandes : `make help`.

En production : [Installation – production](docs/installation-production.md).

## Pages et endpoints

| URL | Rôle |
|---|---|
| `GET /authorize` | Point d'entrée OAuth2 (les applications y redirigent l'utilisateur) |
| `POST /token` | Échange code → jetons, renouvellement (appelé par le backend de l'application) |
| `GET /api/userinfo` | Informations sur l'utilisateur (`Authorization: Bearer <access_token>`) |
| `GET /logout?redirect_uri=…` | Déconnexion globale, puis retour vers l'application |
| `GET /login`, `/register`, `/2fa`, `/reset-password` | Connexion, inscription, code MFA, mot de passe oublié |
| `GET /` | Portail : applications de l'utilisateur |
| `/admin` | Administration (rôle `ROLE_ADMIN`) |

## Stack

PHP 8.4 · Symfony 7.4 LTS · PostgreSQL 16 · league/oauth2-server · scheb/2fa · EasyAdmin 5 ·
FrankenPHP (mode worker) · Docker Compose · Caddy
