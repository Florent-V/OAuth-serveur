# Architecture d'une application cliente

← [Documentation](README.md)

Ce document fixe les **règles à respecter** dans toute application protégée par le serveur, quel que soit son
langage. Elles suivent les standards du domaine :

- [OAuth 2.0 Security Best Current Practice (RFC 9700)](https://www.rfc-editor.org/rfc/rfc9700) ;
- [OAuth 2.0 for Browser-Based Applications](https://datatracker.ietf.org/doc/draft-ietf-oauth-browser-based-apps/) (modèle *BFF*) ;
- [OpenID Connect Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel-1_0.html).

Pour la mise en œuvre pas à pas selon votre type d'application, voir [Adapter une application](adapter-une-application.md).
Pour le détail du protocole (paramètres, requêtes, erreurs), voir [Intégrer une application](integrer-une-application.md).

## Le principe : la partie serveur de l'application est le client OAuth

```
┌──────────────┐  cookie de session   ┌──────────────────────────┐  OAuth2 (serveur à serveur)  ┌─────────────────┐
│  Navigateur  │ ───────────────────▶ │  Partie serveur de l'app  │ ───────────────────────────▶ │  Serveur OAuth  │
│ HTML / JS /  │   HttpOnly, Secure,  │  (Nitro, Python, PHP…)    │  /token, /api/userinfo       │                 │
│ Vue…         │   SameSite=Lax       │  = client confidentiel    │ ◀─────────────────────────── │                 │
└──────────────┘                      │  garde secret et jetons   │  POST logout token           └─────────────────┘
                                      └──────────────────────────┘  (back-channel logout)
```

C'est le modèle **BFF** (*Backend For Frontend*), recommandé pour toute application utilisée dans un navigateur :

- le **navigateur** n'a qu'un **cookie de session** de l'application : il ne voit jamais de jeton ni de secret ;
- la **partie serveur** de l'application mène la connexion OAuth2, garde les jetons, les renouvelle, appelle
  `/api/userinfo` et reçoit les notifications de déconnexion du serveur.

Qui joue ce rôle selon votre application :

| Application | Client OAuth (BFF) |
|---|---|
| Nuxt SSR | Le serveur Nitro de Nuxt |
| Nuxt + backend Python | Le serveur Nitro de Nuxt ; Python est une API interne qui vérifie le JWT transmis |
| HTML/CSS/JS + backend Python | Le backend Python |
| Frontend quelconque + backend quelconque | Le backend |

Une application **sans aucune partie serveur** (SPA pure, site statique) ne peut pas respecter ce modèle : ajoutez-lui
un petit backend.

## Les règles

### 1. Client confidentiel, secret côté serveur

L'application est déclarée comme **client confidentiel** (client ID + client secret). Le secret est une variable
d'environnement de la partie serveur : jamais dans le dépôt, jamais envoyé au navigateur.

### 2. Flux *Authorization Code* avec PKCE et `state`

À chaque connexion : un `state` aléatoire (anti-CSRF) et un `code_verifier` PKCE (`S256`), gardés en session le temps
de l'aller-retour vers le serveur, puis vérifiés au retour. Aucun autre flux (implicite, mot de passe).

### 3. Jetons côté serveur uniquement

Access token et refresh token restent dans la partie serveur : session **côté serveur** (Redis, base, fichiers) ou
cookie **chiffré** (par exemple `nuxt-auth-utils`). Jamais dans le `localStorage`, jamais dans un cookie seulement
signé (lisible par le navigateur), jamais dans une réponse JSON envoyée au front.

### 4. Session de l'application liée au jeton : renouvellement et refus (couche 1)

Une session longue côté application est acceptable **à condition qu'elle reste liée aux jetons** :

- à chaque requête, si l'access token arrive à expiration (15 min), la partie serveur le **renouvelle** avec le
  refresh token, et **enregistre le nouveau refresh token** (il n'est utilisable qu'une fois) ;
- si le serveur **refuse** le renouvellement (`400 invalid_grant` ou `401`), la session de l'application est
  **fermée** : l'accès a été retiré, le compte bloqué ou l'utilisateur déconnecté partout.

Délai de révocation garanti : **15 minutes au plus**, même si une notification se perd. C'est le filet de sécurité de
la règle 5.

Points d'attention :
- **requêtes simultanées** (onglets, appels en parallèle) : un seul renouvellement à la fois par refresh token,
  partagé entre les requêtes (verrou ou cache), sinon le deuxième appel est refusé et déconnecte l'utilisateur ;
- **plusieurs instances** de l'application : ce partage doit passer par un stockage commun (Redis, base) ;
- **rendu serveur** (Nuxt…) : le renouvellement se fait sur la requête de la page, avant le rendu, pour que le nouveau
  cookie parte avec la page (voir [Adapter une application](adapter-une-application.md#1-nuxt-ssr)).

### 5. Back-channel logout : coupure en quelques secondes (couche 3)

L'application expose une route **`POST /auth/backchannel-logout`** (le chemin est libre), déclarée dans l'admin du
serveur comme *URL de déconnexion back-channel*. Quand un utilisateur est révoqué, le serveur y envoie un
*logout token* (JWT signé). L'application :

1. **vérifie** le jeton (voir [la spécification](#le-logout-token)) ; s'il est invalide, elle répond `400` ;
2. **enregistre** « utilisateur `uid` révoqué à telle date » dans un **registre des révocations** ;
3. répond `200` (corps vide ou `{}`), avec `Cache-Control: no-store`.

Puis, **à chaque requête** d'un utilisateur connecté, l'application compare la date d'ouverture de la session
(enregistrée au login) à la date de révocation : si la session est plus ancienne, elle est **fermée immédiatement**.

Le registre des révocations :
- **un enregistrement par utilisateur** : `uid → date de révocation` (la dernière) ;
- **partagé** entre toutes les instances et tous les processus de l'application : Redis, base de données, ou fichier /
  SQLite sur une machine unique. Une variable en mémoire ne suffit que pour un seul processus ;
- **conservé** au moins la durée maximale d'une session de l'application (ex. 7 jours), puis purgeable ;
- comparé avec l'**horloge de l'application** (date de réception), jamais avec des dates venant du serveur.

Cette approche fonctionne avec tout type de session, y compris les cookies chiffrés qu'on ne peut pas supprimer
côté serveur. Avec des sessions côté serveur, on peut aussi supprimer directement les sessions de l'utilisateur.

La route de réception est appelée **par le serveur OAuth, pas par un navigateur** : pas de session, pas de protection
CSRF (la sécurité vient de la signature du jeton), et elle doit être joignable depuis le serveur OAuth.

### 6. Utilisateur identifié par son `id`

La clé de l'utilisateur dans l'application est son **`id`** (`/api/userinfo`) ou **`uid`** (JWT, logout token) :
stable, alors que l'e-mail peut changer. Un rattachement à des comptes existants par e-mail se fait une seule fois,
au premier login.

### 7. Contrôle d'accès : par le serveur

L'accès à l'application est décidé par le serveur (l'administrateur attribue l'application à l'utilisateur). Un
utilisateur sans accès n'arrive jamais sur le callback ; un accès retiré coupe la session (règles 4 et 5).
L'application ne maintient pas de liste d'utilisateurs autorisés en parallèle.

### 8. Front et partie serveur sur le même site

Le front et la partie serveur sont servis **sous le même domaine** (`app1.mydomain.com/` et `app1.mydomain.com/api`,
via le reverse proxy) : le cookie de session est envoyé naturellement, sans CORS ni cookie tiers.

### 9. Déconnexion par le serveur

Le bouton « Se déconnecter » ferme la session de l'application, puis redirige vers
`https://oauth.mydomain.com/logout?redirect_uri=<URL de l'application>`.

### 10. En cas d'erreur, refuser l'accès

Jeton invalide, réponse inattendue, refus du serveur : la session est fermée (*fail-closed*). Si le serveur OAuth est
**injoignable** au moment d'un renouvellement, deux choix sont possibles : fermer la session (le plus sûr, choix
des exemples du dépôt) ou répondre une erreur temporaire (`503`) sans fermer la session ni prolonger l'access
token. Ne jamais continuer à servir un utilisateur dont le jeton a expiré sans renouvellement réussi.

## La révocation, de bout en bout

| Action de l'administrateur | Serveur OAuth | Application conforme |
|---|---|---|
| Retirer l'application à l'utilisateur | Jetons de cette application révoqués, notification envoyée **à cette application** | Session fermée en quelques secondes (règle 5) ; au plus 15 min si la notification se perd (règle 4) |
| « Déconnecter partout » | Sessions du serveur fermées, tous les jetons révoqués, notification **à toutes ses applications** | Idem |
| « Bloquer » | Idem, et compte désactivé | Idem ; plus de reconnexion possible |
| Supprimer le compte | Jetons révoqués, notification à toutes ses applications | Idem |
| Mot de passe réinitialisé (« mot de passe oublié ») | Comme « Déconnecter partout » | Idem |

La déconnexion **volontaire** d'un utilisateur dans une application (bouton « Se déconnecter », règle 9) ferme sa
session sur le serveur et dans cette application ; les autres applications ne sont pas prévenues (le serveur ne
gère pas encore d'identifiant de session `sid` par application, voir la [feuille de route](../ROADMAP.md)) : elles
le seront à la prochaine révocation, et l'utilisateur devra se reconnecter sur le serveur à son prochain passage.

## Le logout token

Requête envoyée par le serveur :

```http
POST /auth/backchannel-logout HTTP/1.1
Host: app1.mydomain.com
Content-Type: application/x-www-form-urlencoded

logout_token=eyJ0eXAiOiJsb2dvdXQrand0IiwiYWxnIjoiUlMyNTYifQ.eyJpc3Mi…
```

En-tête et contenu du jeton :

```json
{ "typ": "logout+jwt", "alg": "RS256" }
{
  "iss": "https://oauth.mydomain.com",
  "aud": "app1",
  "iat": 1790950000,
  "exp": 1790950120,
  "jti": "5d1c0a9e3f7b4c2a8e6d1f0b9a7c3e21",
  "sub": "alice@mydomain.com",
  "uid": 42,
  "events": { "http://schemas.openid.net/event/backchannel-logout": {} }
}
```

Vérifications **obligatoires** avant d'agir (spécification, section 2.6) :

| Vérification | Attendu |
|---|---|
| Signature | RS256 uniquement, avec la clé publique du serveur (`make prod-public-key`) |
| `iss` | L'URL publique du serveur (`DEFAULT_URI` du serveur, sans `/` final) |
| `aud` | Contient le client ID de l'application |
| `iat` / `exp` | Émis il y a moins de quelques minutes, non expiré (le jeton vit 2 minutes) |
| `events` | Contient la clé `http://schemas.openid.net/event/backchannel-logout` (objet vide) |
| `nonce` | **Absent** |
| `uid` | Présent : c'est l'utilisateur à déconnecter (`sub` = son e-mail, comme dans les access tokens) |

Le serveur réessaie deux fois en cas d'erreur réseau ou de réponse `429`/`5xx` : un même jeton (`jti`) peut donc
arriver plusieurs fois, ce qui est sans conséquence (l'enregistrement est idempotent).

## Liste de contrôle

- [ ] Client confidentiel ; secret dans une variable d'environnement de la partie serveur.
- [ ] Authorization Code + PKCE (`S256`) + `state`.
- [ ] Jetons uniquement côté serveur (session serveur ou cookie chiffré).
- [ ] Renouvellement à l'expiration, nouveau refresh token enregistré, un seul renouvellement à la fois.
- [ ] Refus de renouvellement → session fermée.
- [ ] Route de back-channel logout : vérification complète du jeton, réponse 200 / 400.
- [ ] Registre des révocations partagé entre instances ; contrôle à chaque requête.
- [ ] Date d'ouverture de session enregistrée au login.
- [ ] Utilisateur identifié par `id` / `uid`.
- [ ] Front et API sous le même domaine ; cookie `HttpOnly`, `Secure`, `SameSite=Lax`.
- [ ] Déconnexion via `/logout` du serveur.
- [ ] URL de back-channel déclarée dans l'admin, testée avec `app:application:test-backchannel-logout`.
