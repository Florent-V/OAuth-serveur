# Documentation

Serveur d'authentification centralisé (SSO) pour vos applications : un seul compte par personne, une double
authentification par e-mail, et un administrateur qui décide qui a accès à quelle application.

| Je veux… | Document |
|---|---|
| Comprendre comment ça marche (SSO, jetons, MFA, contrôle d'accès) | [Fonctionnement](fonctionnement.md) |
| Installer le serveur sur mon poste pour développer ou essayer | [Installation – développement](installation-developpement.md) |
| Mettre le serveur en production sur mon serveur | [Installation – production](installation-production.md) |
| Ajouter des applications, gérer les utilisateurs, les accès, les comptes | [Administration](administration.md) |
| **Les règles à respecter dans une application cliente** (BFF, jetons, révocation) | [Architecture d'une application cliente](architecture-application-cliente.md) |
| **Protéger une application existante** (Nuxt SSR, Nuxt + Python, HTML/JS + Python, autre) | [Adapter une application](adapter-une-application.md) |
| Référence du protocole : paramètres, requêtes, back-channel logout, erreurs | [Intégrer une application](integrer-une-application.md) |
| Lancer les tests, tester le parcours complet | [Tests](tests.md) |
| Protéger main, configurer Dependabot et CodeQL sur GitHub | [Sécurité GitHub](securite-github.md) |
| Voir les évolutions prévues | [Feuille de route](../ROADMAP.md) |

## En 30 secondes

```
oauth.mydomain.com   ← ce serveur : connexion, inscription, mot de passe oublié, administration
app1.mydomain.com    ← vos applications, qui délèguent la connexion à oauth.mydomain.com
app2.mydomain.com
```

1. Un utilisateur ouvre `app1` : l'application le redirige vers `oauth.mydomain.com`.
2. Il se connecte (mot de passe + code reçu par e-mail), ou crée son compte.
3. Si l'administrateur lui a donné accès à `app1`, il y est renvoyé, connecté. Sinon, il voit
   « Vous n'avez pas accès à l'application app1 ».
4. En ouvrant ensuite `app2`, il est connecté directement, sans ressaisir son mot de passe.

## Démarrage rapide (développement)

```bash
git clone <ce dépôt> oauth && cd oauth
make start                                    # serveur + PostgreSQL + Mailpit
make admin email=moi@example.com              # premier administrateur
```

- Serveur : <http://localhost:8080> — administration : <http://localhost:8080/admin>
- E-mails (codes de connexion) : <http://localhost:8025>

## Glossaire

| Terme | Signification |
|---|---|
| **Application** (ou *client OAuth2*) | Un site ou une appli qui délègue la connexion à ce serveur. Identifiée par un **client ID**. |
| **Client secret** | Mot de passe de l'application, connu seulement de son backend. Les clients *publics* (mobile) n'en ont pas. |
| **Redirect URI** (callback) | URL de l'application vers laquelle le serveur renvoie l'utilisateur après connexion. |
| **Code d'autorisation** | Code à usage unique (10 min) remis à l'application, échangé contre des jetons. |
| **Access token** | Jeton (JWT signé, 15 min) qui prouve l'identité de l'utilisateur auprès de l'application. |
| **Refresh token** | Jeton (1 mois) qui permet d'obtenir un nouvel access token sans reconnecter l'utilisateur. |
| **PKCE** | Protection du code d'autorisation contre l'interception (obligatoire pour les clients publics). |
| **MFA** | Double authentification : code à 6 chiffres envoyé par e-mail à la connexion. |
| **Appareil de confiance** | Navigateur sur lequel le code MFA n'est plus demandé pendant 30 jours. |
| **SSO** | *Single Sign-On* : une connexion unique pour toutes les applications. |
| **Back-channel logout** | Notification envoyée par le serveur à une application (*logout token* signé) pour qu'elle ferme la session d'un utilisateur révoqué. |
| **BFF** | *Backend For Frontend* : la partie serveur de l'application mène la connexion OAuth et garde les jetons ; le navigateur n'a qu'un cookie. |
