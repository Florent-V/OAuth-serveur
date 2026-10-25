# Intégrer une application

← [Documentation](README.md)

Ce guide s'adresse au **développeur d'une application** qui veut déléguer la connexion de ses utilisateurs au
serveur (`https://oauth.mydomain.com` dans les exemples). Il ne suppose aucune connaissance préalable d'OAuth2.

Le serveur parle **OAuth 2.0** standard (flux *Authorization Code* avec PKCE, *Refresh Token*) et
**OpenID Connect Back-Channel Logout** : toute bibliothèque OAuth2 générique convient.

> Avant d'écrire du code : les règles à respecter sont dans
> [Architecture d'une application cliente](architecture-application-cliente.md), et la mise en œuvre pas à pas
> (Nuxt SSR, Nuxt + Python, HTML/JS + Python, cas général) dans
> [Adapter une application](adapter-une-application.md). Ce guide-ci est la référence du protocole.

## Sommaire

1. [Ce qu'il vous faut](#1-ce-quil-vous-faut)
2. [Paramètres du serveur](#2-paramètres-du-serveur)
3. [Le flux de connexion, pas à pas](#3-le-flux-de-connexion-pas-à-pas)
4. [Identifier l'utilisateur](#4-identifier-lutilisateur)
5. [Garder l'utilisateur connecté : session et renouvellement](#5-garder-lutilisateur-connecté--session-et-renouvellement)
6. [Déconnexion](#6-déconnexion)
7. [Back-channel logout : déconnexion immédiate](#7-back-channel-logout--déconnexion-immédiate)
8. [Exemples de code](#8-exemples-de-code) : PHP, Symfony, Node.js, Nuxt, Python, Grafana
9. [Tester son intégration](#9-tester-son-intégration)
10. [Erreurs fréquentes](#10-erreurs-fréquentes)
11. [Liste de contrôle avant la mise en production](#11-liste-de-contrôle-avant-la-mise-en-production)

## 1. Ce qu'il vous faut

Demandez à l'administrateur du serveur de **déclarer votre application** (voir [Administration](administration.md#déclarer-une-application)).
Indiquez-lui :

- le **nom** de l'application et son **URL** (ex. `https://app1.mydomain.com/`) ;
- la ou les **redirect URIs** (callback) : l'URL de votre application qui recevra l'utilisateur après connexion,
  ex. `https://app1.mydomain.com/oauth/callback` (et `http://localhost:3000/oauth/callback` pour le développement) ;
- le **type de client** :

| Votre application | Type | Ce que vous recevez |
|---|---|---|
| Site ou API avec un **backend** (PHP, Node, Python, Java…) — cas le plus courant | **Confidentiel** | client ID + **client secret** |
| Application **mobile** ou **de bureau** (le code est chez l'utilisateur) | **Public** | client ID seul ; **PKCE obligatoire** |
| Application JavaScript **avec rendu serveur** (Nuxt SSR, Next.js, SvelteKit, Remix…) | **Confidentiel** | client ID + **client secret**, utilisés uniquement par la partie serveur. Voir [Adapter une application](adapter-une-application.md#1-nuxt-ssr). |
| Application **JavaScript sans backend** (SPA pure, site statique) | — | Pas encore possible directement (le serveur n'envoie pas d'en-têtes CORS). Ajoutez un petit backend (*BFF*) en client confidentiel — un framework avec rendu serveur comme Nuxt fait exactement cela. |

Vous recevez un **client ID** (ex. `app1`) et, pour un client confidentiel, un **client secret**. Le secret est
affiché une seule fois à l'administrateur : conservez-le comme un mot de passe (variable d'environnement, coffre à
secrets), **jamais** dans le code source ni côté navigateur.

L'administrateur doit aussi **vous donner accès** à l'application (ou cocher *Inscription ouverte*) : sinon,
même connecté, vous verrez « Vous n'avez pas accès à l'application ».

## 2. Paramètres du serveur

| Paramètre | Valeur |
|---|---|
| Authorization endpoint | `https://oauth.mydomain.com/authorize` |
| Token endpoint | `https://oauth.mydomain.com/token` |
| User info endpoint | `https://oauth.mydomain.com/api/userinfo` |
| Logout | `https://oauth.mydomain.com/logout?redirect_uri=<URL de votre application>` |
| Scopes | `profile email` (séparés par un espace) |
| Grant types | `authorization_code`, `refresh_token` |
| PKCE | `S256` ; obligatoire pour les clients publics, recommandé pour tous |
| Authentification du client sur `/token` | `client_id` + `client_secret` dans le corps (`client_secret_post`) ou en HTTP Basic (`client_secret_basic`) |
| Access token | JWT signé **RS256**, valable **15 minutes** |
| Refresh token | valable **1 mois**, **à usage unique** (un nouveau est fourni à chaque renouvellement) |
| Clé publique (vérification des JWT) | fournie par l'administrateur (`make prod-public-key`) |
| Back-channel logout | `POST` d'un *logout token* (JWT RS256) vers l'URL déclarée pour l'application ([section 7](#7-back-channel-logout--déconnexion-immédiate)) |
| Émetteur (`iss` des logout tokens) | l'URL publique du serveur, ex. `https://oauth.mydomain.com` |

Il n'y a pas (encore) de découverte OpenID Connect (`/.well-known/openid-configuration`) ni d'`id_token` : configurez
les URL ci-dessus à la main. Voir la [feuille de route](../ROADMAP.md).

## 3. Le flux de connexion, pas à pas

```mermaid
sequenceDiagram
    autonumber
    actor U as Navigateur
    participant A as Votre application
    participant S as Serveur OAuth2
    U->>A: clic « Se connecter »
    A->>A: génère state + code_verifier, les garde en session
    A->>U: 302 vers /authorize?…&state&code_challenge
    U->>S: connexion (mot de passe, code MFA) si nécessaire
    S->>U: 302 vers votre redirect_uri?code=…&state=…
    U->>A: GET /oauth/callback?code=…&state=…
    A->>A: vérifie state
    A->>S: POST /token (code, code_verifier, client_id, client_secret)
    S->>A: access_token + refresh_token
    A->>A: identifie l'utilisateur, ouvre SA session
```

### 3.1 Rediriger l'utilisateur vers le serveur

Quand l'utilisateur doit se connecter, votre application génère deux valeurs aléatoires et **les garde dans la
session** de l'utilisateur :

- `state` : protège contre les attaques CSRF (vous vérifierez au retour que c'est le même) ;
- `code_verifier` (PKCE) : une chaîne aléatoire de 43 à 128 caractères ; vous en envoyez l'empreinte
  `code_challenge = BASE64URL(SHA256(code_verifier))`.

Puis elle redirige le navigateur vers :

```
https://oauth.mydomain.com/authorize
    ?response_type=code
    &client_id=app1
    &redirect_uri=https%3A%2F%2Fapp1.mydomain.com%2Foauth%2Fcallback
    &scope=profile%20email
    &state=Rz8Hc1…
    &code_challenge=E9Melhoa2Owv…
    &code_challenge_method=S256
```

| Paramètre | Obligatoire | Valeur |
|---|---|---|
| `response_type` | oui | `code` |
| `client_id` | oui | votre client ID |
| `redirect_uri` | oui | une redirect URI **déclarée**, à l'identique (schéma, domaine, port, chemin) |
| `scope` | non | `profile email` (par défaut) ; `email` seul si vous n'avez pas besoin du nom |
| `state` | recommandé | valeur aléatoire gardée en session |
| `code_challenge`, `code_challenge_method` | public : oui ; confidentiel : recommandé | empreinte du `code_verifier`, `S256` |

Le serveur s'occupe de tout le reste : connexion, inscription, code MFA, mot de passe oublié, contrôle d'accès.
Les pages sont affichées aux couleurs de votre application si l'administrateur les a personnalisées.

> Si l'utilisateur **n'a pas accès** à votre application, il voit une page « Vous n'avez pas accès » sur le
> serveur et **ne revient pas** vers votre application. Rien à gérer de votre côté.

### 3.2 Recevoir l'utilisateur sur la redirect URI

Le serveur renvoie le navigateur vers votre redirect URI :

```
https://app1.mydomain.com/oauth/callback?code=def50200a8f3…&state=Rz8Hc1…
```

Votre application doit :

1. vérifier que `state` est égal à celui gardé en session (sinon : refuser) ;
2. si l'URL contient `error` à la place de `code` (ex. `error=invalid_scope`), afficher une erreur ;
3. échanger le code **immédiatement** (il expire au bout de 10 minutes et ne sert qu'une fois).

### 3.3 Échanger le code contre des jetons

Depuis votre **backend** (jamais depuis le navigateur pour un client confidentiel) :

```bash
curl -X POST https://oauth.mydomain.com/token \
  -d grant_type=authorization_code \
  -d client_id=app1 \
  -d client_secret=VOTRE_SECRET \
  -d redirect_uri=https://app1.mydomain.com/oauth/callback \
  -d code=def50200a8f3… \
  -d code_verifier=LE_CODE_VERIFIER_GARDÉ_EN_SESSION
```

Pour un client public, omettez `client_secret` (le `code_verifier` est alors obligatoire).

Réponse :

```json
{
  "token_type": "Bearer",
  "expires_in": 900,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJ1aWQiOjQyLCJlbWFpbCI6…",
  "refresh_token": "def50200b1c7…"
}
```

Conservez les jetons **côté serveur** (session de votre application, base de données chiffrée), pas dans le
`localStorage` du navigateur.

## 4. Identifier l'utilisateur

Deux méthodes, combinables.

### A. Appeler `/api/userinfo` (le plus simple)

```bash
curl -H "Authorization: Bearer <access_token>" https://oauth.mydomain.com/api/userinfo
```

```json
{ "sub": "alice@mydomain.com", "id": "3f6c2b1e-8d4a-4c7e-9b2f-5a1d0e7c4b93", "preferred_username": "alice", "name": "alice", "email": "alice@mydomain.com" }
```

| Champ | Présent si | Description |
|---|---|---|
| `id` | toujours | **Identifiant stable** de l'utilisateur (UUID, chaîne de 36 caractères). À utiliser comme clé dans votre base. |
| `sub` | toujours | Identifiant de connexion (l'e-mail). Peut changer. |
| `email` | scope `email` | Adresse e-mail (vérifiée). |
| `preferred_username` | scope `profile` | **Pseudo** choisi à l'inscription, unique (insensible à la casse). Peut être modifié par un administrateur : ne pas l'utiliser comme clé. |
| `name` | scope `profile` | Même valeur que `preferred_username` (conservé pour compatibilité). |

Réponses d'erreur : **401** si le jeton est invalide, expiré ou révoqué (accès retiré, compte bloqué…) ; **403** si
votre application a été désactivée ou que l'utilisateur n'y a plus accès. Dans les deux cas : considérez l'utilisateur comme déconnecté.

Avantage : chaque appel vérifie l'état **actuel** (accès retiré, compte bloqué → refus immédiat).

### B. Vérifier le JWT localement (sans appel réseau)

L'access token est un JWT signé avec la clé privée du serveur. Avec la **clé publique** (fichier PEM transmis par
l'administrateur), votre application peut le vérifier elle-même. Contenu (*claims*) :

```json
{
  "aud": ["app1"],
  "jti": "5d1c…",
  "iat": 1790812800, "nbf": 1790812800, "exp": 1790813700,
  "sub": "alice@mydomain.com",
  "scopes": ["profile", "email"],
  "uid": "3f6c2b1e-8d4a-4c7e-9b2f-5a1d0e7c4b93",
  "email": "alice@mydomain.com",
  "preferred_username": "alice",
  "name": "alice"
}
```

Vérifiez **toujours** : la signature (algorithme `RS256` uniquement), `exp` (expiration), et que `aud` contient
**votre** client ID (un jeton émis pour une autre application ne doit pas être accepté). Utilisez une bibliothèque
reconnue : `firebase/php-jwt` ou `lcobucci/jwt` (PHP), `jose` (Node.js), `PyJWT` (Python).

Limite : un JWT reste valable jusqu'à son expiration (15 min) même si l'accès est retiré entre-temps. Si c'est
gênant, appelez `/api/userinfo` pour les actions sensibles, ou au renouvellement.

### Faire le lien avec vos comptes existants

Au premier passage, rattachez l'utilisateur par son `id` (colonne `sso_id` par exemple). Si votre application a
déjà des comptes, vous pouvez faire le lien **une fois** par l'adresse e-mail (vérifiée par le serveur), puis
n'utiliser que l'`id` : l'e-mail peut être modifié par l'administrateur.

## 5. Garder l'utilisateur connecté : session et renouvellement

Après le callback, **ouvrez votre propre session** (cookie de votre application) : l'utilisateur ne repasse pas par
le serveur à chaque page.

Si vous avez besoin de l'access token au-delà de 15 minutes (appels à `/api/userinfo`, à une API…), renouvelez-le
avec le refresh token :

```bash
curl -X POST https://oauth.mydomain.com/token \
  -d grant_type=refresh_token \
  -d client_id=app1 \
  -d client_secret=VOTRE_SECRET \
  -d refresh_token=def50200b1c7…
```

La réponse contient un **nouvel access token et un nouveau refresh token** : **remplacez les deux**. L'ancien
refresh token ne fonctionne plus (rotation, ce qui limite les dégâts en cas de vol).

Si le renouvellement échoue (`400 invalid_grant` : refresh token expiré ou révoqué, accès retiré, compte bloqué,
« déconnecter partout »), **fermez la session locale** et renvoyez l'utilisateur vers la connexion.

Stratégie simple et sûre : à chaque requête, si l'access token a expiré, le renouveler ; en cas d'échec,
déconnecter. Vous respectez ainsi les révocations faites par l'administrateur sous 15 minutes au plus, même sans
back-channel logout. Deux requêtes simultanées ne doivent pas renouveler le même refresh token (la seconde serait
refusée) : partagez un renouvellement en cours ([architecture, règle 4](architecture-application-cliente.md#4-session-de-lapplication-liée-au-jeton--renouvellement-et-refus-couche-1)).

Pour une coupure en quelques secondes, ajoutez le back-channel logout ([section 7](#7-back-channel-logout--déconnexion-immédiate)).

Si l'utilisateur revient après la fin de votre session, redirigez-le simplement vers `/authorize` : s'il est
encore connecté sur le serveur, il revient aussitôt sans rien saisir (SSO).

## 6. Déconnexion

Le bouton « Se déconnecter » de votre application doit :

1. détruire la session locale (et oublier les jetons) ;
2. rediriger vers `https://oauth.mydomain.com/logout?redirect_uri=https://app1.mydomain.com/`.

Le serveur ferme sa session (l'utilisateur est déconnecté de **toutes** les applications au prochain passage par
le serveur) puis renvoie vers `redirect_uri`. Cette adresse doit avoir la même origine (schéma + domaine + port)
que l'URL de l'application ou l'une de ses redirect URIs déclarées ; sinon l'utilisateur reste sur la page de
connexion du serveur.

## 7. Back-channel logout : déconnexion immédiate

Standard [OpenID Connect Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel-1_0.html).
Quand un utilisateur est **bloqué**, **déconnecté partout**, **perd l'accès** à l'application, est **supprimé** ou
réinitialise son mot de passe, le serveur envoie, de serveur à serveur, un *logout token* à chaque application
concernée qui a déclaré une **URL de déconnexion back-channel** (admin → Applications, ou option
`--backchannel-logout-uri` de `app:application:create`).

```http
POST /auth/backchannel-logout HTTP/1.1
Host: app1.mydomain.com
Content-Type: application/x-www-form-urlencoded

logout_token=eyJ0eXAiOiJsb2dvdXQrand0IiwiYWxnIjoiUlMyNTYifQ…
```

```json
{
  "iss": "https://oauth.mydomain.com",
  "aud": "app1",
  "iat": 1790950000, "exp": 1790950120,
  "jti": "5d1c0a9e3f7b4c2a8e6d1f0b9a7c3e21",
  "sub": "alice@mydomain.com",
  "uid": "3f6c2b1e-8d4a-4c7e-9b2f-5a1d0e7c4b93",
  "events": { "http://schemas.openid.net/event/backchannel-logout": {} }
}
```

L'application :

1. vérifie le jeton : signature RS256 (clé publique du serveur), `iss`, `aud` = son client ID, `iat`/`exp`, présence
   de l'événement, absence de `nonce` ([détail](architecture-application-cliente.md#le-logout-token)) ; sinon `400` ;
2. enregistre « utilisateur `uid` révoqué maintenant » dans un registre partagé ;
3. répond `200` ;
4. à chaque requête, ferme toute session de cet utilisateur ouverte **avant** cette date.

Le serveur envoie les notifications juste après l'action de l'administrateur, en parallèle, avec 5 s de délai
d'attente et deux nouvelles tentatives en cas d'erreur réseau ou `429`/`5xx`. Un échec définitif est journalisé ;
l'accès est alors coupé au renouvellement suivant du jeton (≤ 15 min).

Tester l'URL d'une application (l'utilisateur est réellement déconnecté de cette application) :

```bash
make prod-console c="app:application:test-backchannel-logout app1 alice@mydomain.com"
#  [OK] https://app1.mydomain.com/auth/backchannel-logout a répondu HTTP 200 : notification acceptée.
```

## 8. Exemples de code

### PHP avec league/oauth2-client

```bash
composer require league/oauth2-client
```

```php
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;

$provider = new GenericProvider([
    'clientId'                => getenv('OAUTH_CLIENT_ID'),
    'clientSecret'            => getenv('OAUTH_CLIENT_SECRET'),
    'redirectUri'             => 'https://app1.mydomain.com/oauth/callback',
    'urlAuthorize'            => 'https://oauth.mydomain.com/authorize',
    'urlAccessToken'          => 'https://oauth.mydomain.com/token',
    'urlResourceOwnerDetails' => 'https://oauth.mydomain.com/api/userinfo',
    'scopes'                  => ['profile', 'email'],
    'scopeSeparator'          => ' ',
    'pkceMethod'              => AbstractProvider::PKCE_METHOD_S256,
]);

// /login
$url = $provider->getAuthorizationUrl();
$_SESSION['oauth_state'] = $provider->getState();
$_SESSION['oauth_pkce'] = $provider->getPkceCode();
header('Location: '.$url);
exit;

// /oauth/callback
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? null)) {
    exit('State invalide');
}
$provider->setPkceCode($_SESSION['oauth_pkce']);
$token = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
$user = $provider->getResourceOwner($token)->toArray();   // ['id' => '3f6c2b1e-8d4a-4c7e-9b2f-5a1d0e7c4b93', 'email' => …, 'name' => …]

$_SESSION['user_id'] = $user['id'];
$_SESSION['token'] = $token->jsonSerialize();             // access + refresh token

// Plus tard : renouvellement
$token = new \League\OAuth2\Client\Token\AccessToken($_SESSION['token']);
if ($token->hasExpired()) {
    $token = $provider->getAccessToken('refresh_token', ['refresh_token' => $token->getRefreshToken()]);
    $_SESSION['token'] = $token->jsonSerialize();
}
```

### Symfony avec knpuniversity/oauth2-client-bundle

```bash
composer require knpuniversity/oauth2-client-bundle league/oauth2-client
```

```yaml
# config/packages/knpu_oauth2_client.yaml
knpu_oauth2_client:
    clients:
        sso:
            type: generic
            provider_class: League\OAuth2\Client\Provider\GenericProvider
            client_id: '%env(OAUTH_CLIENT_ID)%'
            client_secret: '%env(OAUTH_CLIENT_SECRET)%'
            redirect_route: oauth_check
            redirect_params: {}
            provider_options:
                urlAuthorize: 'https://oauth.mydomain.com/authorize'
                urlAccessToken: 'https://oauth.mydomain.com/token'
                urlResourceOwnerDetails: 'https://oauth.mydomain.com/api/userinfo'
                scopeSeparator: ' '
```

```php
#[Route('/oauth/login', name: 'oauth_start')]
public function start(ClientRegistry $clients): Response
{
    return $clients->getClient('sso')->redirect(['profile', 'email'], []);
}

#[Route('/oauth/callback', name: 'oauth_check')]
public function check(): Response
{
    // Intercepté par votre authenticator (voir ci-dessous)
}
```

Créez un authenticator qui étend `KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator` :
`supports()` répond vrai pour la route `oauth_check` ; `authenticate()` appelle
`$client->getAccessToken()` puis `$client->fetchUserFromToken($token)->toArray()` et retrouve (ou crée)
l'utilisateur local par son `id`. Le bundle gère le `state`. Documentation :
<https://github.com/knpuniversity/oauth2-client-bundle#authenticating-with-the-new-symfony-authenticator>.

### Node.js (Express, sans bibliothèque OAuth)

```js
import express from 'express';
import session from 'express-session';
import crypto from 'node:crypto';

const SERVER = 'https://oauth.mydomain.com';
const CLIENT_ID = process.env.OAUTH_CLIENT_ID;
const CLIENT_SECRET = process.env.OAUTH_CLIENT_SECRET;
const REDIRECT_URI = 'https://app1.mydomain.com/oauth/callback';
const b64url = (buf) => buf.toString('base64url');

const app = express();
app.use(session({ secret: process.env.SESSION_SECRET, resave: false, saveUninitialized: false,
                  cookie: { httpOnly: true, secure: true, sameSite: 'lax' } }));

app.get('/login', (req, res) => {
  req.session.state = b64url(crypto.randomBytes(16));
  req.session.verifier = b64url(crypto.randomBytes(48));
  const params = new URLSearchParams({
    response_type: 'code', client_id: CLIENT_ID, redirect_uri: REDIRECT_URI, scope: 'profile email',
    state: req.session.state,
    code_challenge: b64url(crypto.createHash('sha256').update(req.session.verifier).digest()),
    code_challenge_method: 'S256',
  });
  res.redirect(`${SERVER}/authorize?${params}`);
});

app.get('/oauth/callback', async (req, res) => {
  if (!req.query.state || req.query.state !== req.session.state) return res.status(400).send('State invalide');
  const tokenRes = await fetch(`${SERVER}/token`, {
    method: 'POST',
    body: new URLSearchParams({
      grant_type: 'authorization_code', client_id: CLIENT_ID, client_secret: CLIENT_SECRET,
      redirect_uri: REDIRECT_URI, code: req.query.code, code_verifier: req.session.verifier,
    }),
  });
  if (!tokenRes.ok) return res.status(401).send('Connexion refusée');
  const tokens = await tokenRes.json();
  const user = await (await fetch(`${SERVER}/api/userinfo`, {
    headers: { Authorization: `Bearer ${tokens.access_token}` },
  })).json();
  req.session.regenerate(() => {
    req.session.user = user;        // { id, email, name, sub }
    req.session.tokens = tokens;
    res.redirect('/');
  });
});

app.get('/logout', (req, res) => {
  req.session.destroy(() => res.redirect(`${SERVER}/logout?redirect_uri=${encodeURIComponent('https://app1.mydomain.com/')}`));
});
```

Pour vérifier le JWT localement : bibliothèque [`jose`](https://github.com/panva/jose)
(`importSPKI(pem, 'RS256')` puis `jwtVerify(token, key, { audience: CLIENT_ID, algorithms: ['RS256'] })`).

### Nuxt (SSR), Nuxt + Python, HTML/JS + Python

Voir [Adapter une application](adapter-une-application.md) : mise en œuvre complète (connexion, renouvellement,
back-channel logout, pièges du rendu serveur) pour Nuxt, et le module Python `oauth_client.py` pour les backends Python.

### Application existante avec « OAuth2 générique » : exemple Grafana

Beaucoup d'applications auto-hébergées acceptent un fournisseur OAuth2 générique. Pour Grafana (`grafana.ini`) :

```ini
[auth.generic_oauth]
enabled = true
name = Mon compte
client_id = grafana
client_secret = VOTRE_SECRET
scopes = profile email
auth_url = https://oauth.mydomain.com/authorize
token_url = https://oauth.mydomain.com/token
api_url = https://oauth.mydomain.com/api/userinfo
email_attribute_path = email
login_attribute_path = email
name_attribute_path = name
use_pkce = true
```

Redirect URI à déclarer : `https://grafana.mydomain.com/login/generic_oauth`. Les applications qui exigent
**OpenID Connect** avec découverte automatique (certaines versions de Nextcloud, Gitea, Proxmox…) ne sont pas
encore compatibles : voir la [feuille de route](../ROADMAP.md).

## 9. Tester son intégration

- **Serveur de développement** : déclarez votre application avec une redirect URI `http://localhost:<port>/…`
  sur le serveur de dev (<http://localhost:8080/admin>) ; les e-mails (codes) arrivent dans Mailpit.
- **À la main** : ouvrez l'URL `/authorize` de la section 3.1 dans un navigateur, copiez le `code` de l'URL de retour
  (même si votre application n'existe pas encore), puis échangez-le avec la commande `curl` de la section 3.3
  (dans les 10 minutes).

- **Back-channel logout** : `make console c="app:application:test-backchannel-logout app1 moi@example.com"`
  envoie un logout token à votre URL et affiche la réponse.

Cas à vérifier : utilisateur sans accès, accès retiré pendant une session (session fermée immédiatement par
back-channel, sinon au renouvellement), compte bloqué, déconnexion depuis votre application puis depuis une autre
application. Liste complète : [Adapter une application, section 5](adapter-une-application.md#5-vérifier-lapplication-adaptée).

## 10. Erreurs fréquentes

| Symptôme | Cause | Solution |
|---|---|---|
| `/authorize` répond `401 {"error":"invalid_client"}` | client ID inconnu **ou redirect URI non déclarée** (même une différence de `/` final ou de `http`/`https`) | Comparez caractère par caractère avec la liste de l'admin. |
| Retour sur la redirect URI avec `error=invalid_scope` | scope demandé inconnu | N'utilisez que `profile` et `email`. |
| `/authorize` répond 400 (client public) | `code_challenge` manquant | PKCE obligatoire pour les clients publics. |
| `/token` répond `401 invalid_client` | mauvais client secret, ou application désactivée | Vérifiez le secret (régénéré ?) et le statut de l'application. |
| `/token` répond `400 invalid_request` | `redirect_uri` différente de celle envoyée à `/authorize`, ou paramètre manquant | Envoyez exactement la même `redirect_uri`. |
| `/token` répond `400 invalid_grant` | code expiré (10 min), déjà utilisé, mauvais `code_verifier`, ou refresh token déjà utilisé / révoqué | Recommencez la connexion ; pour le renouvellement, stockez toujours le **nouveau** refresh token. |
| `/api/userinfo` répond 401 | jeton expiré ou révoqué, en-tête `Authorization: Bearer` absent | Renouvelez le jeton, sinon reconnectez l'utilisateur. |
| `/api/userinfo` répond 403 | application désactivée, ou accès de l'utilisateur retiré | Déconnectez l'utilisateur. |
| L'utilisateur voit « Vous n'avez pas accès à l'application » | aucun accès attribué | L'administrateur doit lui donner l'accès (ou activer *Inscription ouverte*). |
| Après déconnexion, l'utilisateur reste sur la page de connexion du serveur | `redirect_uri` de `/logout` hors de l'origine de votre application | Utilisez l'URL de l'application déclarée. |
| Logout token refusé par l'application : `iss` invalide | `DEFAULT_URI` du serveur différent de l'URL attendue par l'application | `DEFAULT_URI` = URL publique exacte du serveur, sans `/` final ; même valeur côté application |
| « Échec du back-channel logout » dans les journaux du serveur | URL injoignable depuis le serveur (DNS, pare-feu, nom Docker), ou réponse ≠ 2xx | Tester avec `app:application:test-backchannel-logout` ; l'URL doit être joignable par le serveur OAuth, pas seulement par le navigateur |
| Notification acceptée (200) mais l'utilisateur reste connecté | Registre des révocations non partagé entre instances, ou non consulté à chaque requête, ou date de login absente de la session | Registre dans Redis / la base ; contrôle dans un middleware global ; `logged_in_at` enregistré au callback |
| State invalide au retour | session de votre application perdue entre l'aller et le retour (cookie `SameSite=Strict`, domaine différent) | Cookie de session en `SameSite=Lax`, même domaine pour `/login` et le callback. |

## 11. Liste de contrôle avant la mise en production

- [ ] HTTPS partout ; redirect URIs de production en `https://`, sans URI de développement.
- [ ] Client secret dans une variable d'environnement ou un coffre à secrets, jamais dans le dépôt ni le navigateur.
- [ ] `state` vérifié au retour ; PKCE (`S256`) activé.
- [ ] Jetons stockés côté serveur ; nouveau refresh token enregistré à chaque renouvellement.
- [ ] Utilisateur identifié par `id` (et non par l'e-mail).
- [ ] Si vérification locale du JWT : signature RS256, `exp` et `aud` vérifiés.
- [ ] Échec de renouvellement ou `/api/userinfo` en 401/403 → déconnexion locale.
- [ ] Bouton de déconnexion qui passe par `/logout?redirect_uri=…`.
- [ ] Session de votre application renouvelée après connexion (protection contre la fixation de session).
- [ ] URL de back-channel logout déclarée et testée ; logout token entièrement vérifié ; registre des révocations
      partagé et consulté à chaque requête.
- [ ] Règles de [l'architecture d'une application cliente](architecture-application-cliente.md) respectées.
