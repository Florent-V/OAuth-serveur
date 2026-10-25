# Adapter une application existante

← [Documentation](README.md)

Ce guide explique comment protéger une application existante (aujourd'hui sans authentification, ou branchée sur
un autre fournisseur) avec le serveur. Il applique les règles de
[l'architecture d'une application cliente](architecture-application-cliente.md) à quatre cas :

1. [Nuxt SSR](#1-nuxt-ssr)
2. [Nuxt + backend Python](#2-nuxt--backend-python)
3. [Front HTML/CSS/JS + backend Python](#3-front-htmlcssjs--backend-python)
4. [Frontend + backend quelconques](#4-frontend--backend-quelconques)
5. [Vérifier l'application adaptée](#5-vérifier-lapplication-adaptée)

Le serveur parle des protocoles standard (OAuth 2.0, OpenID Connect Back-Channel Logout) : c'est la partie serveur de
chaque application qui s'adapte, quel que soit son langage. Le principe est toujours le même :

| Rôle | Où |
|---|---|
| Connexion (`/auth/login` → serveur → `/auth/callback`) | Partie serveur de l'application |
| Jetons (access, refresh) et client secret | Partie serveur de l'application, jamais le navigateur |
| Renouvellement toutes les 15 min, refus → session fermée (couche 1) | Partie serveur, à chaque requête |
| Réception des logout tokens, registre des révocations (couche 3) | Partie serveur : `POST /auth/backchannel-logout` |
| Navigateur | Un cookie de session, et des appels `/api/…` sur le même domaine |

## 0. Avant de commencer (tous les cas)

1. **Déclarer l'application** sur le serveur (admin `/admin` → Applications → Créer, ou en ligne de commande) :
   ```bash
   make prod-console c='app:application:create "Application 1" --id=app1 \
       --home-url=https://app1.mydomain.com/ \
       --redirect-uri=https://app1.mydomain.com/auth/callback \
       --backchannel-logout-uri=https://app1.mydomain.com/auth/backchannel-logout'
   ```
   Notez le **client secret** (affiché une seule fois). Pour le développement, déclarez une seconde application
   (`app1-dev`) avec des URL en `http://localhost:…`.
2. **Récupérer la clé publique** du serveur (vérification des logout tokens et des JWT) :
   `make prod-public-key > oauth-public.pem`.
3. **Donner l'accès** aux utilisateurs concernés (`make prod-grant email=… app=app1`, ou depuis l'admin).
4. **Variables d'environnement** de la partie serveur de l'application :

   | Variable | Exemple |
   |---|---|
   | URL du serveur | `https://oauth.mydomain.com` (c'est aussi l'émetteur `iss` des logout tokens) |
   | Client ID / secret | `app1` / `…` |
   | Redirect URI | `https://app1.mydomain.com/auth/callback` |
   | Clé publique | contenu ou chemin de `oauth-public.pem` |
   | Secret de session de l'application | `openssl rand -hex 32` |

5. **Rattacher vos comptes existants** : si l'application a déjà une table d'utilisateurs, ajoutez-y une colonne
   `sso_id` de type texte (`VARCHAR(36)` ou `UUID`) pour l'`id` du serveur, un UUID. Au premier login d'un
   utilisateur, retrouvez son compte par e-mail une seule fois, enregistrez `sso_id`, puis n'utilisez plus que lui.
6. **Même domaine** : servez le front et la partie serveur sous le même domaine (voir la
   [configuration Caddy](#même-domaine-pour-le-front-et-lapi) plus bas).

## 1. Nuxt SSR

La partie serveur de Nuxt (Nitro) est le client OAuth, avec
[nuxt-auth-utils](https://github.com/atinux/nuxt-auth-utils) (Nuxt 4).

### Étapes

1. `npm install nuxt-auth-utils`, puis ajoutez le module et la configuration à `nuxt.config.ts` :
   ```ts
   export default defineNuxtConfig({
     compatibilityDate: '2025-07-15',
     modules: ['nuxt-auth-utils'],
     runtimeConfig: {
       // Côté serveur uniquement (jamais envoyé au navigateur). Valeurs surchargées par les variables
       // d'environnement NUXT_OAUTH_SERVER_URL, NUXT_OAUTH_CLIENT_ID, NUXT_OAUTH_CLIENT_SECRET, NUXT_OAUTH_REDIRECT_URI,
       // NUXT_OAUTH_PUBLIC_KEY (clé publique PEM du serveur) et NUXT_OAUTH_ISSUER.
       oauth: {
         serverUrl: 'https://oauth.mydomain.com',
         clientId: '',
         clientSecret: '',
         redirectUri: 'https://app1.mydomain.com/auth/callback',
         // Back-channel logout : vérification des logout tokens envoyés par le serveur
         publicKey: '',
         issuer: '', // vide = serverUrl
       },
     },
   })
   ```
2. Copiez de l'exemple, dans votre application :

   | Fichier | Rôle |
   |---|---|
   | `shared/types/auth.d.ts` | Types de la session (`user`, `loggedInAt`, jetons dans `secure`) |
   | `server/utils/oauth.ts` | Appels à `/token` et `/api/userinfo`, `getAccessToken()` (renouvellement partagé), `endSession()` |
   | `server/utils/backchannel-logout.ts` | Vérification des logout tokens (Web Crypto) et registre des révocations |
   | `server/routes/auth/login.get.ts` | Redirection vers le serveur (state, PKCE, page de retour) |
   | `server/routes/auth/callback.get.ts` | Échange du code, ouverture de la session (`loggedInAt`) |
   | `server/routes/auth/logout.get.ts` | Déconnexion locale puis globale |
   | `server/routes/auth/backchannel-logout.post.ts` | Réception des logout tokens |
   | `server/middleware/oauth-session.ts` | À chaque requête : session révoquée ? jeton à renouveler ? |
   | `app/middleware/auth.ts` | Pages réservées : `definePageMeta({ middleware: 'auth' })` |

3. Variables d'environnement :
   ```dotenv
   NUXT_OAUTH_SERVER_URL=https://oauth.mydomain.com
   NUXT_OAUTH_CLIENT_ID=app1
   NUXT_OAUTH_CLIENT_SECRET=…
   NUXT_OAUTH_REDIRECT_URI=https://app1.mydomain.com/auth/callback
   NUXT_OAUTH_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----
   …
   -----END PUBLIC KEY-----"
   NUXT_SESSION_PASSWORD=…           # 32 caractères minimum
   ```
4. **Registre des révocations en production** : l'exemple utilise le stockage Nitro `data` (fichiers locaux). Avec
   plusieurs instances, montez-le sur Redis :
   ```ts
   // nuxt.config.ts
   nitro: { storage: { data: { driver: 'redis', url: process.env.REDIS_URL } } },
   ```
5. Protégez les pages (`definePageMeta({ middleware: 'auth' })`) et les API : dans chaque route `server/api/…`
   protégée, `await requireUserSession(event)` (ou `getAccessToken(event)` si la route a besoin du jeton).
6. Côté Vue : `useUserSession()` donne `loggedIn` et `user` ; le bouton de déconnexion pointe vers `/auth/logout`
   (lien externe : `<a href="/auth/logout">`).

### Trois pièges propres à Nuxt (gérés par l'exemple)

1. **Le cookie renouvelé pendant le rendu serveur est perdu.** Un `useFetch('/api/…')` exécuté pendant le rendu
   est un appel interne : le cookie de session qu'il met à jour n'est pas renvoyé au navigateur, qui garde un
   refresh token déjà consommé. → Le renouvellement se fait dans `server/middleware/oauth-session.ts`, sur la
   requête de la page elle-même, avant le rendu.
2. **Renouvellements simultanés.** Plusieurs appels en parallèle renouvelleraient le même refresh token ; seul le
   premier est accepté. → `getAccessToken()` partage un renouvellement en cours (`pendingRefreshes`). Avec plusieurs
   instances, activez l'affinité de session sur le répartiteur de charge (ou un verrou Redis).
3. **Une session fermée en cours de requête réapparaît.** `clearUserSession()` envoie un cookie vide au navigateur,
   mais h3 relit ensuite la session depuis le cookie de la requête en cours : la suite de cette requête (rendu,
   `/api/_auth/session`) voit encore l'utilisateur. → Utilisez `endSession()`, qui retire aussi le cookie de la
   requête :
   ```ts
   /**
    * Ferme la session de l'utilisateur.
    *
    * clearUserSession() envoie un cookie vide au navigateur, mais h3 relit ensuite la session depuis le cookie
    * de la requête en cours : sans la suite, le reste de cette requête (rendu de la page, /api/_auth/session…)
    * verrait encore l'utilisateur connecté. On retire donc aussi le cookie de la requête.
    */
   export async function endSession(event: H3Event): Promise<void> {
     await clearUserSession(event)
     const name = useRuntimeConfig(event).session?.name || 'nuxt-session'
     event.node.req.headers.cookie = (event.node.req.headers.cookie ?? '')
       .split(';')
       .filter((cookie: string) => !cookie.trim().startsWith(`${name}=`))
       .join(';')
   }
   ```

Le middleware serveur, cœur des couches 1 et 3 :

```ts
// server/middleware/oauth-session.ts
/**
 * Au début de chaque requête (page ou API) d'un utilisateur connecté :
 *
 * 1. Back-channel logout : si le serveur OAuth2 a révoqué l'utilisateur après l'ouverture de la session,
 *    la session est fermée immédiatement.
 * 2. Renouvellement des jetons si l'access token arrive à expiration ; s'il est refusé (accès retiré,
 *    compte bloqué…), la session est fermée. C'est aussi le filet de sécurité si une notification se perd.
 *
 * Le renouvellement doit avoir lieu ici, sur la requête de la page elle-même : un useFetch('/api/…')
 * exécuté pendant le rendu serveur est un appel interne, dont le cookie de session mis à jour n'est PAS
 * renvoyé au navigateur (qui garderait un refresh token déjà consommé). Les appels internes de la même
 * page réutilisent les jetons obtenus (voir pendingRefreshes).
 */
export default defineEventHandler(async (event) => {
  // Toutes les requêtes, y compris /api/_auth/session (useUserSession côté navigateur),
  // sauf les routes de connexion et les fichiers statiques
  if (/^\/(auth\/|_nuxt\/|__nuxt)/.test(event.path)) {
    return
  }
  const session = await getUserSession(event)
  if (!session.user || !session.secure) {
    return
  }
  if ((session.loggedInAt ?? 0) <= await revokedAt(session.user.id)) {
    await endSession(event)
    return
  }
  // En cas d'échec, la session est fermée : la page s'affiche déconnectée
  await getAccessToken(event).catch(() => {})
})
```

Et la réception des logout tokens :

```ts
// server/routes/auth/backchannel-logout.post.ts
// 4. Back-channel logout : le serveur OAuth2 prévient (de serveur à serveur) qu'un utilisateur doit être déconnecté.
//    URL à déclarer dans l'admin du serveur : https://app1.mydomain.com/auth/backchannel-logout
export default defineEventHandler(async (event) => {
  setResponseHeader(event, 'Cache-Control', 'no-store')
  const body = await readBody<{ logout_token?: string }>(event)

  try {
    const uid = await verifyLogoutToken(event, String(body?.logout_token ?? ''))
    // Toutes les sessions de cet utilisateur ouvertes avant maintenant deviennent invalides
    await markRevoked(uid)
    return {}
  }
  catch (error) {
    setResponseStatus(event, 400)
    return { error: 'invalid_request', error_description: (error as Error).message }
  }
})
```

L'exemple a été testé de bout en bout contre le serveur (access tokens de 20 s pour forcer les renouvellements) :
renouvellement pendant le rendu serveur, 5 appels simultanés pour un seul renouvellement, accès retiré, « Déconnecter
partout » avec fermeture immédiate de la session par back-channel, logout token falsifié refusé, SSO, déconnexion.

## 2. Nuxt + backend Python

```
Navigateur ──cookie──▶ Nuxt (Nitro) ──Authorization: Bearer <access token>──▶ API Python (réseau interne)
                         client OAuth (BFF)                                     vérifie le JWT localement
```

- **Nuxt** est le client OAuth, exactement comme au [cas 1](#1-nuxt-ssr) : connexion, jetons, renouvellement,
  back-channel logout. Rien de tout cela n'est à faire dans Python.
- **Python** est une API interne : il reçoit l'access token de l'utilisateur, transmis par Nitro, et le **vérifie
  localement** (signature, expiration, destinataire). Pas d'introspection : quand l'accès est retiré, Nuxt ferme la
  session et n'appelle plus Python ; un jeton déjà émis expire en 15 minutes au plus.
- L'API Python **n'est pas exposée** sur Internet : seul Nitro l'appelle (réseau Docker, `localhost`, ou règle de
  pare-feu). Le navigateur passe toujours par Nuxt.

### Côté Nuxt : relayer les appels vers Python avec le jeton

```ts
// server/api/py/[...path].ts — /api/py/images/42 → http://python:8000/images/42
export default defineEventHandler(async (event) => {
  const accessToken = await getAccessToken(event)   // renouvelé si besoin ; 401 si la session est révoquée
  const path = getRouterParam(event, 'path')
  return proxyRequest(event, `${useRuntimeConfig(event).pythonApiUrl}/${path}`, {
    headers: { Authorization: `Bearer ${accessToken}` },
  })
})
```

(`pythonApiUrl` : variable `NUXT_PYTHON_API_URL`, dans `runtimeConfig` côté serveur.)

### Côté Python : vérifier l'access token

Dépendance : `PyJWT[crypto]`. Exemple avec FastAPI (le principe est identique avec Flask ou Django) :

```python
import os
import jwt
from fastapi import Depends, FastAPI, Header, HTTPException

PUBLIC_KEY = open(os.environ["OAUTH_PUBLIC_KEY_FILE"]).read()
CLIENT_ID = os.environ["OAUTH_CLIENT_ID"]   # le client ID de l'application Nuxt (« aud » des jetons)

def current_user(authorization: str = Header(default="")) -> dict:
    scheme, _, token = authorization.partition(" ")
    if scheme.lower() != "bearer" or not token:
        raise HTTPException(401, "Jeton manquant")
    try:
        claims = jwt.decode(token, PUBLIC_KEY, algorithms=["RS256"], audience=CLIENT_ID,
                            options={"require": ["exp", "aud", "uid"]})
    except jwt.PyJWTError:
        raise HTTPException(401, "Jeton invalide ou expiré")
    return {"id": claims["uid"], "email": claims.get("email"), "name": claims.get("name")}

app = FastAPI()

@app.post("/images/{image_id}/process")
def process(image_id: int, user: dict = Depends(current_user)):
    ...  # user["id"] = identifiant stable de l'utilisateur
```

Contenu d'un access token : `aud` (client ID), `exp`, `sub` (e-mail), `uid` (identifiant stable, UUID), `email`, `name`, `preferred_username` (pseudo), `scopes`.
Il n'y a pas de claim `iss` dans les access tokens : la signature et `aud` suffisent.

### Traitements longs (images, PDF)

Un jeton n'est vérifié qu'à la réception de la requête : un traitement lancé avant une révocation va jusqu'au bout.
Si c'est important, revérifiez l'accès **à la remise du résultat** (téléchargement servi par Nuxt, donc soumis à la
session) ou, pour un traitement très long, entre deux étapes en appelant `/api/userinfo` avec le jeton (401/403 =
accès retiré).

## 3. Front HTML/CSS/JS + backend Python

```
Navigateur (HTML/JS) ──cookie──▶ Backend Python ──▶ Serveur OAuth
  fetch('/api/…')                 client OAuth (BFF)
```

Le backend Python fait tout le travail du client OAuth ; le JavaScript ne manipule jamais de jeton. Le code
ci-dessous (Flask) **n'a pas été exécuté dans ce dépôt** : il est à intégrer et valider dans votre application.

### Le module OAuth (indépendant du framework)

Dépendances : `requests`, `PyJWT[crypto]`. L'état partagé (registre des révocations, renouvellements en cours) est dans
SQLite : correct avec plusieurs processus sur une même machine (gunicorn). Sur plusieurs machines, remplacez SQLite
par Redis ou votre base de données.

```python
# oauth_client.py
"""Client OAuth2 côté serveur (BFF) pour le serveur SSO : indépendant du framework web.

Dépendances : requests, PyJWT[crypto]. État partagé (révocations, renouvellements) dans SQLite :
fonctionne avec plusieurs processus sur une même machine (gunicorn, uvicorn --workers).
Sur plusieurs machines, remplacez SQLite par Redis ou votre base de données.
"""
import base64
import hashlib
import json
import os
import secrets
import sqlite3
import time
from urllib.parse import urlencode

import jwt
import requests

OAUTH_SERVER = os.environ["OAUTH_SERVER_URL"].rstrip("/")         # https://oauth.mydomain.com
CLIENT_ID = os.environ["OAUTH_CLIENT_ID"]
CLIENT_SECRET = os.environ["OAUTH_CLIENT_SECRET"]
REDIRECT_URI = os.environ["OAUTH_REDIRECT_URI"]                    # https://app1.mydomain.com/auth/callback
ISSUER = os.environ.get("OAUTH_ISSUER", OAUTH_SERVER)              # « iss » des logout tokens
PUBLIC_KEY = open(os.environ["OAUTH_PUBLIC_KEY_FILE"]).read()      # clé publique du serveur (make prod-public-key)
STATE_DB = os.environ.get("OAUTH_STATE_DB", "oauth_state.sqlite3")
LOGOUT_EVENT = "http://schemas.openid.net/event/backchannel-logout"


class OAuthError(Exception):
    """Refus du serveur OAuth2 (code invalide, accès retiré, compte bloqué…)."""


def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def _db() -> sqlite3.Connection:
    db = sqlite3.connect(STATE_DB, timeout=15, isolation_level=None)
    db.execute("CREATE TABLE IF NOT EXISTS revocations (uid TEXT PRIMARY KEY, revoked_at REAL)")
    db.execute("CREATE TABLE IF NOT EXISTS refreshes (old_token TEXT PRIMARY KEY, tokens TEXT, created_at REAL)")
    return db


# --- 1. Connexion ---------------------------------------------------------------------------------

def authorization_request() -> tuple[str, dict]:
    """URL de /authorize et valeurs à garder en session jusqu'au retour (state, PKCE)."""
    pending = {"state": _b64url(secrets.token_bytes(16)), "verifier": _b64url(secrets.token_bytes(48))}
    url = f"{OAUTH_SERVER}/authorize?" + urlencode({
        "response_type": "code",
        "client_id": CLIENT_ID,
        "redirect_uri": REDIRECT_URI,
        "scope": "profile email",
        "state": pending["state"],
        "code_challenge": _b64url(hashlib.sha256(pending["verifier"].encode()).digest()),
        "code_challenge_method": "S256",
    })
    return url, pending


def _token_request(**params) -> dict:
    response = requests.post(f"{OAUTH_SERVER}/token", timeout=10,
                             data={"client_id": CLIENT_ID, "client_secret": CLIENT_SECRET, **params})
    if response.status_code in (400, 401):
        raise OAuthError(response.json().get("error", "refus"))
    response.raise_for_status()
    return response.json()


def finish_login(query: dict, pending: dict) -> tuple[dict, dict]:
    """Traite le retour sur la redirect URI : renvoie (utilisateur, jetons à garder côté serveur)."""
    if "error" in query:
        raise OAuthError(query["error"])
    if not pending or not secrets.compare_digest(query.get("state", ""), pending["state"]):
        raise OAuthError("state invalide")
    tokens = _token_request(grant_type="authorization_code", code=query.get("code", ""),
                            redirect_uri=REDIRECT_URI, code_verifier=pending["verifier"])
    user = requests.get(f"{OAUTH_SERVER}/api/userinfo", timeout=10,
                        headers={"Authorization": f"Bearer {tokens['access_token']}"}).json()
    return {"id": user["id"], "email": user.get("email"), "name": user.get("name")}, _for_session(tokens)


def _for_session(tokens: dict) -> dict:
    lifetime = tokens["expires_in"]
    return {
        "access_token": tokens["access_token"],
        "refresh_token": tokens["refresh_token"],
        # renouvellement 30 s avant l'expiration (à mi-vie si le jeton est très court)
        "refresh_at": time.time() + max(lifetime - 30, lifetime / 2),
    }


def logout_url(home_url: str) -> str:
    return f"{OAUTH_SERVER}/logout?" + urlencode({"redirect_uri": home_url})


# --- 2. Couche 1 : renouvellement des jetons ------------------------------------------------------

def fresh_tokens(tokens: dict) -> dict:
    """Jetons valides, renouvelés si nécessaire. OAuthError si le serveur refuse : fermer la session.

    Le refresh token n'est utilisable qu'une fois : des requêtes simultanées (plusieurs onglets, appels
    en parallèle) partagent le même renouvellement grâce à un verrou SQLite.
    """
    if time.time() < tokens["refresh_at"]:
        return tokens
    old = tokens["refresh_token"]
    db = _db()
    try:
        db.execute("BEGIN IMMEDIATE")  # verrou en écriture, entre processus
        row = db.execute("SELECT tokens FROM refreshes WHERE old_token = ?", (old,)).fetchone()
        if row:
            new = json.loads(row[0])   # déjà renouvelé par une requête concurrente
        else:
            new = _for_session(_token_request(grant_type="refresh_token", refresh_token=old))
            db.execute("INSERT INTO refreshes VALUES (?, ?, ?)", (old, json.dumps(new), time.time()))
            db.execute("DELETE FROM refreshes WHERE created_at < ?", (time.time() - 60,))
        db.execute("COMMIT")
        return new
    except Exception:
        db.execute("ROLLBACK")
        raise
    finally:
        db.close()


# --- 3. Couche 3 : back-channel logout -------------------------------------------------------------

def verify_logout_token(logout_token: str) -> int:
    """Vérifie un logout token (OpenID Connect Back-Channel Logout 1.0) et renvoie l'uid de l'utilisateur."""
    claims = jwt.decode(logout_token, PUBLIC_KEY, algorithms=["RS256"], audience=CLIENT_ID, issuer=ISSUER,
                        leeway=60, options={"require": ["iss", "aud", "iat", "exp", "jti", "events", "uid"]})
    if not isinstance(claims["events"].get(LOGOUT_EVENT), dict) or "nonce" in claims:
        raise jwt.InvalidTokenError("pas un logout token")
    if abs(time.time() - claims["iat"]) > 300:
        raise jwt.InvalidTokenError("jeton trop ancien")
    return str(claims["uid"])


def mark_revoked(uid: str) -> None:
    """Toutes les sessions de cet utilisateur ouvertes avant maintenant deviennent invalides."""
    db = _db()
    db.execute("INSERT OR REPLACE INTO revocations VALUES (?, ?)", (uid, time.time()))
    db.close()


def is_revoked(uid: str, logged_in_at: float) -> bool:
    db = _db()
    row = db.execute("SELECT revoked_at FROM revocations WHERE uid = ?", (uid,)).fetchone()
    db.close()
    return bool(row) and logged_in_at <= row[0]
```

### Les routes et le contrôle à chaque requête (Flask)

Dépendances : `flask`, `flask-session` (+ `redis` en production). Les sessions sont **côté serveur** : la session
par défaut de Flask est seulement signée, ses données (dont les jetons) seraient lisibles par le navigateur.

```python
# app.py
import os
import time

import jwt
from flask import Flask, jsonify, redirect, request, session
from flask_session import Session

import oauth_client as oauth

app = Flask(__name__)
app.config.update(
    SECRET_KEY=os.environ["SESSION_SECRET"],
    SESSION_TYPE="redis",                 # sessions côté serveur (redis, filesystem via cachelib, sqlalchemy…)
    SESSION_COOKIE_SECURE=True,
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",
    PERMANENT_SESSION_LIFETIME=7 * 24 * 3600,
)
Session(app)
HOME_URL = os.environ["APP_HOME_URL"]     # https://app1.mydomain.com/


@app.get("/auth/login")
def login():
    url, pending = oauth.authorization_request()
    session["oauth_pending"] = pending
    return redirect(url)


@app.get("/auth/callback")
def callback():
    try:
        user, tokens = oauth.finish_login(request.args, session.pop("oauth_pending", None))
    except oauth.OAuthError:
        return redirect("/?login_error=1")
    session.clear()
    app.session_interface.regenerate(session)      # nouvel identifiant de session (fixation)
    session.update(user=user, tokens=tokens, logged_in_at=time.time())
    return redirect(HOME_URL)


@app.get("/auth/logout")
def logout():
    session.clear()
    return redirect(oauth.logout_url(HOME_URL))


@app.post("/auth/backchannel-logout")
def backchannel_logout():
    try:
        uid = oauth.verify_logout_token(request.form.get("logout_token", ""))
    except jwt.PyJWTError as error:
        return jsonify(error="invalid_request", error_description=str(error)), 400, {"Cache-Control": "no-store"}
    oauth.mark_revoked(uid)
    return jsonify({}), 200, {"Cache-Control": "no-store"}


@app.before_request
def check_session():
    """Couches 3 puis 1, à chaque requête d'un utilisateur connecté."""
    user = session.get("user")
    if not user or request.path.startswith("/auth/"):
        return None
    if oauth.is_revoked(user["id"], session["logged_in_at"]):    # back-channel logout reçu
        session.clear()
    else:
        try:
            session["tokens"] = oauth.fresh_tokens(session["tokens"])   # renouvellement (rotation)
        except Exception:
            session.clear()                                            # refus du serveur : accès retiré…
    if "user" not in session and request.path.startswith("/api/"):
        return jsonify(error="unauthenticated"), 401
    return None


@app.get("/api/me")
def me():
    if "user" not in session:
        return jsonify(error="unauthenticated"), 401
    return jsonify(session["user"])
```

Avec **FastAPI**, la même logique s'écrit avec un middleware de session côté serveur (par exemple
`starlette-session` avec Redis) et une dépendance qui fait le travail de `check_session()`. Les fonctions de
`oauth_client.py` ne changent pas.

### Le JavaScript

```js
// Au chargement : qui est connecté ?
const response = await fetch('/api/me', { credentials: 'same-origin' })
if (response.status === 401) {
  location.href = '/auth/login'          // ou afficher un bouton « Se connecter » qui pointe vers /auth/login
} else {
  const user = await response.json()     // { id, email, name, preferred_username }
}

// Tous les appels à l'API : une réponse 401 signifie « session fermée » (révocation, expiration)
async function api(path, options = {}) {
  const r = await fetch(path, { credentials: 'same-origin', ...options })
  if (r.status === 401) {
    location.href = '/auth/login'
    throw new Error('Session fermée')
  }
  return r
}
```

Bouton de déconnexion : `<a href="/auth/logout">Se déconnecter</a>`.

### Même domaine pour le front et l'API

```caddyfile
app1.mydomain.com {
	# API Python et routes de connexion
	handle /api/* {
		reverse_proxy 127.0.0.1:8000
	}
	handle /auth/* {
		reverse_proxy 127.0.0.1:8000
	}
	# Front statique
	handle {
		root * /srv/app1/public
		file_server
	}
}
```

La route `/auth/backchannel-logout` doit être joignable **depuis le serveur OAuth** : si les deux tournent sur la
même machine, déclarez de préférence l'URL publique (`https://app1.mydomain.com/auth/backchannel-logout`).

## 4. Frontend + backend quelconques

Le schéma est celui du [cas 3](#3-front-htmlcssjs--backend-python), dans le langage du backend. Ce qu'il faut
implémenter dans la partie serveur :

| Élément | Comment |
|---|---|
| `GET /auth/login` | `state` + PKCE en session, redirection vers `/authorize` ([détail](integrer-une-application.md#31-rediriger-lutilisateur-vers-le-serveur)) |
| `GET /auth/callback` | Vérifier `state`, échanger le code sur `/token` (avec `client_secret` et `code_verifier`), lire `/api/userinfo`, nouvelle session avec `user`, jetons et `logged_in_at` |
| Contrôle à chaque requête | Session révoquée (registre) → fermer ; jeton à renouveler → `/token` (`grant_type=refresh_token`), enregistrer le nouveau refresh token, un seul renouvellement à la fois ; refus → fermer |
| `POST /auth/backchannel-logout` | Vérifier le logout token ([liste des contrôles](architecture-application-cliente.md#le-logout-token)), enregistrer `uid → maintenant`, répondre 200 (400 si invalide) |
| `GET /auth/logout` | Fermer la session, rediriger vers `/logout?redirect_uri=…` du serveur |
| API | 401 si pas de session ; le front redirige vers `/auth/login` |

Bibliothèques utiles :

| Langage | Flux OAuth2 (login, callback, refresh) | Vérification du logout token (JWT RS256) | Sessions côté serveur |
|---|---|---|---|
| PHP | `league/oauth2-client` (`GenericProvider`, PKCE) — exemple dans [le guide d'intégration](integrer-une-application.md#php-avec-leagueoauth2-client) | `firebase/php-jwt`, `lcobucci/jwt` | Sessions PHP natives, Symfony |
| Symfony | `knpuniversity/oauth2-client-bundle` | `lcobucci/jwt`, `web-token/jwt-library` | Session Symfony (Redis, PDO) |
| Node.js | `fetch` (voir l'exemple Express du guide) ou `openid-client` en mode OAuth2 | `jose` | `express-session` + Redis |
| Python | `requests` ou `authlib` | `PyJWT[crypto]` | Flask-Session, Django sessions |
| Java | Spring Security OAuth2 Client (configuration manuelle des URL, pas de découverte) | `nimbus-jose-jwt` | Spring Session |
| Go | `golang.org/x/oauth2` | `github.com/golang-jwt/jwt/v5` | `gorilla/sessions` + Redis |

Les modules « OpenID Connect » des frameworks qui exigent une URL de découverte (`/.well-known/…`) ne fonctionnent pas
encore avec ce serveur : utilisez leur mode OAuth2 avec les URL saisies à la main.

## 5. Vérifier l'application adaptée

Sur le serveur de développement (`make start`) ou de production :

| Test | Attendu |
|---|---|
| Ouvrir une page protégée sans être connecté | Redirection vers la connexion, puis retour sur la page demandée |
| Utilisateur sans accès à l'application | Page « Vous n'avez pas accès » du serveur ; l'application ne reçoit rien |
| Inspecter les cookies et les réponses JSON du navigateur | Aucun access token, refresh token ni client secret |
| Attendre 15 minutes (ou mettre `OAUTH_ACCESS_TOKEN_TTL=PT1M` en dev) et naviguer | Toujours connecté (renouvellement silencieux) |
| `make prod-console c="app:application:test-backchannel-logout app1 alice@mydomain.com"` | « HTTP 200 » ; Alice est déconnectée de l'application à sa requête suivante |
| Retirer l'application à un utilisateur connecté | Session fermée à la requête suivante (back-channel) |
| « Déconnecter partout » / « Bloquer » dans l'admin | Idem, dans toutes ses applications |
| Couper la route de back-channel (ou la déclarer fausse), puis retirer l'accès | Session fermée au plus tard au renouvellement suivant (≤ 15 min) : le filet de sécurité fonctionne |
| Bouton « Se déconnecter » | Retour sur l'application, déconnecté ; la page du serveur demande à nouveau le mot de passe |

En cas d'échec de la notification, le serveur l'écrit dans ses journaux (`make prod-logs`, message « Échec du
back-channel logout ») avec la raison : application injoignable, code HTTP, etc.
