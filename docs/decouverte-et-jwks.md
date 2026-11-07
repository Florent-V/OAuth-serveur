# Découverte automatique (`.well-known`) et JWKS

← [Documentation](README.md)

Le serveur publie un document de métadonnées décrivant ses endpoints et ses capacités, et un JWKS avec ses clés
publiques de signature. Une application n'a plus besoin que de trois valeurs : `issuer`, `client_id` et
`client_secret`. C'est **additif** : les applications configurées à la main (URL par URL) continuent de fonctionner
sans changement.

| URL | Contenu |
|---|---|
| `GET /.well-known/oauth-authorization-server` | Métadonnées, [RFC 8414](https://www.rfc-editor.org/rfc/rfc8414) |
| `GET /.well-known/openid-configuration` | Même document (convention OpenID Connect Discovery) |
| `GET /.well-known/jwks.json` | Clés publiques de signature (`jwks_uri`) |

Réponses publiques : `200`, `application/json`, `Cache-Control: public, max-age=3600`, `Access-Control-Allow-Origin: *`.
Les URL du document sont construites à partir de `DEFAULT_URI`, jamais de l'en-tête `Host` de la requête.

## Configurer un client par découverte

Valeurs à fournir : `issuer` (la valeur de `DEFAULT_URI` du serveur, sans `/` final), `client_id`, `client_secret`.
Le client vérifie que le champ `issuer` du document est **strictement égal** à l'issuer configuré (RFC 8414), puis lit
`authorization_endpoint`, `token_endpoint`, `userinfo_endpoint` et `jwks_uri`.

### Authlib (Python)

```python
import requests, jwt
from authlib.integrations.requests_client import OAuth2Session

ISSUER = "https://oauth.mydomain.com"
metadata = requests.get(f"{ISSUER}/.well-known/oauth-authorization-server", timeout=10).json()
assert metadata["issuer"] == ISSUER
assert "S256" in metadata["code_challenge_methods_supported"]

oauth = OAuth2Session("mon-client-id", "mon-secret", scope="profile email",
                      redirect_uri="https://app1.mydomain.com/oauth/callback", code_challenge_method="S256")
url, state = oauth.create_authorization_url(metadata["authorization_endpoint"], code_verifier=verifier)
# … redirection de l'utilisateur, puis au retour :
token = oauth.fetch_token(metadata["token_endpoint"], authorization_response=callback_url, code_verifier=verifier)

# Vérification d'un jeton : la clé est choisie dans le JWKS d'après l'en-tête « kid »
jwks = jwt.PyJWKClient(metadata["jwks_uri"])
claims = jwt.decode(token["access_token"], jwks.get_signing_key_from_jwt(token["access_token"]).key,
                    algorithms=["RS256"], audience="mon-client-id")
```

Un script complet (flux PKCE S256 + logout token vérifié via `jwks_uri`) est dans
[`tests/Interop/discovery_client.py`](../tests/Interop/discovery_client.py).

### Symfony

Avec `knpuniversity/oauth2-client-bundle` (voir [Intégrer une application](integrer-une-application.md)), les URL restent
saisies dans la configuration du bundle ; pour les lire dans le document plutôt que les copier, chargez-le une fois
et gardez-le en cache une heure :

```php
$metadata = $cache->get('oauth_metadata', function (ItemInterface $item) use ($http, $issuer): array {
    $item->expiresAfter(3600);
    $metadata = $http->request('GET', $issuer.'/.well-known/oauth-authorization-server')->toArray();
    if ($metadata['issuer'] !== $issuer) {
        throw new \RuntimeException('issuer inattendu');
    }

    return $metadata;
});
// $metadata['authorization_endpoint'], ['token_endpoint'], ['userinfo_endpoint'], ['jwks_uri']
```

### Ce que le document déclare

Uniquement des capacités réelles : `response_types_supported: ["code"]`, `response_modes_supported: ["query"]`,
`grant_types_supported: ["authorization_code", "refresh_token"]`, `code_challenge_methods_supported: ["S256"]`,
`token_endpoint_auth_methods_supported: ["client_secret_basic", "client_secret_post", "none"]` (« none » : clients
publics avec PKCE), `scopes_supported`, `backchannel_logout_supported: true`,
`backchannel_logout_session_supported: false` (les logout tokens n'ont pas de `sid`),
`authorization_response_iss_parameter_supported: false`.

Le document est généré par `App\Security\Discovery\ServerMetadata` : les endpoints viennent des routes montées, les
grants et scopes des paramètres `app.oauth.grant_types` et `app.oauth.scopes` (`config/services.yaml`). Si vous activez
un grant dans `league_oauth2_server.yaml`, ajoutez-le à `app.oauth.grant_types`.

### Issuer avec un chemin

Si `DEFAULT_URI` contient un chemin (`https://auth.example.com/oauth`), les deux conventions sont servies :
RFC 8414 insère `/.well-known/…` entre l'hôte et le chemin (`/.well-known/oauth-authorization-server/oauth`), OIDC
l'ajoute après le chemin (`/oauth/.well-known/openid-configuration`). Les autres combinaisons répondent `404`.

## Le `kid` des jetons

Tous les jetons signés (access tokens et logout tokens) portent l'en-tête `kid` : l'empreinte
[RFC 7638](https://www.rfc-editor.org/rfc/rfc7638) (SHA-256, base64url) de la clé publique. Les clients qui
l'ignoraient continuent de fonctionner. Ni `iss`, ni les autres claims, ni l'algorithme (RS256) n'ont changé.

## Rotation des clés

### Quand

Clé compromise ou suspectée (fuite, départ d'une personne ayant accès au serveur), renouvellement périodique,
migration vers une nouvelle machine. Tant que vous n'en faites pas, laissez `OAUTH_ADDITIONAL_PUBLIC_KEYS` **vide** :
le JWKS publie alors seulement la clé en cours, et rien ne change.

### Principe

La clé active (`OAUTH_PRIVATE_KEY`) signe les access tokens (15 min par défaut) et les logout tokens (2 min). Les
clés listées dans `OAUTH_ADDITIONAL_PUBLIC_KEYS` (chemins de fichiers PEM **publics**, séparés par des virgules) sont
publiées dans le JWKS en plus. Remplacer la clé d'un coup rendrait les jetons en circulation invérifiables : on
**publie** donc la nouvelle clé d'abord, on **signe** avec elle ensuite, et on **retire** l'ancienne en dernier. La
rotation n'est pas automatique.

### Procédure (déploiement Docker)

Les clés sont dans le volume `config/jwt/` du conteneur `php`. On garde la même phrase de passe
(`OAUTH_PASSPHRASE`), qui n'a donc pas à changer.

1. **Générer la nouvelle paire** dans le conteneur (`make prod-sh`) :
   ```bash
   cd config/jwt
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes256 -pass env:OAUTH_PASSPHRASE -out next-private.pem
   openssl pkey -in next-private.pem -passin env:OAUTH_PASSPHRASE -pubout -out next-public.pem
   ```
2. **Publier la nouvelle clé** : dans `.env.docker`, `OAUTH_ADDITIONAL_PUBLIC_KEYS=/app/config/jwt/next-public.pem`,
   puis `make prod-up`. Vérifiez que `/.well-known/jwks.json` contient deux clés.
3. **Attendre 1 heure** (durée du cache du JWKS côté clients).
4. **Basculer la signature** (dans `config/jwt/`) :
   ```bash
   mv private.pem old-private.pem && mv public.pem old-public.pem
   mv next-private.pem private.pem && mv next-public.pem public.pem
   ```
   Dans `.env.docker`, `OAUTH_ADDITIONAL_PUBLIC_KEYS=/app/config/jwt/old-public.pem`, puis `make prod-up`. Les nouveaux
   jetons portent le `kid` de la nouvelle clé ; l'ancienne reste publiée.
5. **Attendre environ 1 h 15** : durée de vie maximale d'un access token (`OAUTH_ACCESS_TOKEN_TTL`) plus cache du JWKS.
6. **Retirer l'ancienne clé** : videz `OAUTH_ADDITIONAL_PUBLIC_KEYS`, `make prod-up`, puis supprimez `old-*.pem`.

### Limites

- Après l'étape 4, `/api/userinfo` valide les jetons avec la seule clé active : un access token signé avec l'ancienne
  clé y reçoit un `401` jusqu'à son expiration (15 min au plus). L'application doit alors le renouveler. Les refresh
  tokens ne sont pas des JWT et ne sont pas concernés.
- Les applications qui vérifient avec un PEM copié à la main (sans `jwks_uri`) doivent recevoir la nouvelle clé
  publique à l'étape 4 (`make prod-public-key`). Celles qui lisent le JWKS n'ont rien à faire.
- Cette procédure n'a pas été rejouée sur un serveur de production : faites-la d'abord sur un environnement de test.

## Écarts restants vers une conformité OpenID Connect

Le serveur est un serveur d'autorisation OAuth2 conforme à la RFC 8414 ; il n'est **pas** un fournisseur OpenID
Connect. Écarts, et ce que chacun empêche :

- **Pas d'`id_token`** (ni scope `openid`) : les bibliothèques OIDC strictes (qui exigent un `id_token`, un `nonce`,
  `id_token_signing_alg_values_supported`, `subject_types_supported`) ne peuvent pas l'utiliser comme fournisseur ; les
  clients doivent lire `/api/userinfo` ou le JWT d'accès. Ces champs sont donc absents du document.
- **Pas de claim `iss` dans les access tokens** : un client qui valide `iss` sur le jeton d'accès doit s'en dispenser
  (les logout tokens ont bien `iss`, égal à l'`issuer` publié). Ajouter `iss` est une évolution à part.
- **`iss` absent de la réponse d'autorisation (RFC 9207)** : un client ne peut pas détecter une attaque par confusion
  de serveur d'autorisation s'il parle à plusieurs serveurs. Sans conséquence avec un seul serveur.
- **Pas de `revocation_endpoint` ni d'`introspection_endpoint`** (RFC 7009, RFC 7662) : un client ne peut pas révoquer
  un jeton lui-même ni vérifier en ligne qu'un jeton est actif ; il s'appuie sur la durée de vie courte des access
  tokens et sur le back-channel logout.
- **Pas de `end_session_endpoint`** (RP-Initiated Logout) : `/logout?redirect_uri=…` est propre à ce serveur ; les
  clients OIDC ne le découvrent pas.
- **Back-channel logout sans `sid`** : on ne peut pas déconnecter une seule session ; la déconnexion vise
  l'utilisateur entier.
- **PKCE `plain` encore accepté par la bibliothèque** : non publié, mais non refusé. La RFC 9700 recommande de le refuser.
- **Pas d'enregistrement dynamique de clients** (RFC 7591) : les applications sont déclarées par l'administrateur.
