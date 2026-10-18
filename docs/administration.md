# Administration

← [Documentation](README.md)

Tout se gère depuis l'interface **`/admin`** (réservée aux administrateurs), ou en ligne de commande.

Les commandes sont données sous trois formes, selon votre environnement :

| Environnement | Préfixe |
|---|---|
| Production (Docker) | `make prod-console c="app:…"` ou raccourci `make prod-…` |
| Développement (Docker) | `make console c="app:…"` ou raccourci `make …` |
| Sans Docker | `php bin/console app:…` |

## Sommaire

1. [Administrateurs](#administrateurs)
2. [Déclarer une application](#déclarer-une-application)
3. [Personnaliser la page de connexion](#personnaliser-la-page-de-connexion-dune-application)
4. [Gérer les utilisateurs](#gérer-les-utilisateurs)
5. [Donner ou retirer l'accès à une application](#donner-ou-retirer-laccès-à-une-application)
6. [Révoquer un utilisateur](#révoquer-un-utilisateur)
7. [Ce que les utilisateurs font eux-mêmes](#ce-que-les-utilisateurs-font-eux-mêmes)
8. [Référence des commandes](#référence-des-commandes)

## Administrateurs

Le premier administrateur se crée en ligne de commande (le mot de passe est demandé) :

```bash
make prod-admin email=moi@mydomain.com
```

Ensuite, dans `/admin` → **Utilisateurs** → éditer un compte → **Rôles** : cochez *Administrateur*. Un
administrateur est aussi un utilisateur normal (il ne voit sur le portail que les applications qui lui sont
attribuées).

Conseil : ayez **deux administrateurs**, pour ne pas vous retrouver bloqué si l'un perd l'accès à sa messagerie.

## Déclarer une application

Chaque site ou application qui utilise le serveur doit être déclaré. Vous obtenez un **client ID** et, pour une
application serveur, un **client secret**, à transmettre au développeur de l'application avec le guide
[Intégrer une application](integrer-une-application.md).

### Depuis l'administration

`/admin` → **Applications** → **Créer** :

| Champ | Description |
|---|---|
| **Nom** | Affiché aux utilisateurs (connexion, portail, « accès refusé »). |
| **Client ID** | Identifiant technique, ex. `app1`. Laissez vide pour un identifiant aléatoire. **Non modifiable** ensuite. |
| **Description** | Note interne. |
| **URL de l'application** | Ex. `https://app1.mydomain.com/`. Lien du portail ; autorise aussi le retour après déconnexion. |
| **Redirect URIs** | Les URL de retour (callback) de l'application, **une par ligne**, ex. `https://app1.mydomain.com/auth/callback`. Comparées **exactement** : schéma, domaine, port et chemin. |
| **URL de déconnexion back-channel** | Facultatif, recommandé. Ex. `https://app1.mydomain.com/auth/backchannel-logout`. Le serveur y envoie un *logout token* dès qu'un utilisateur est bloqué, déconnecté partout ou perd l'accès : l'application ferme sa session en quelques secondes. Doit être joignable **depuis le serveur**. |
| **Client public** | À cocher pour une application mobile ou de bureau, qui ne peut pas garder de secret (PKCE obligatoire). Laissez décoché pour un site avec un backend (cas habituel). Uniquement à la création. |
| **Active** | Décochée, l'application est suspendue : connexion refusée avec un message, jetons inutilisables. |
| **Inscription ouverte** | Cochée, une personne qui crée son compte **depuis cette application** y a accès immédiatement. Sinon, vous devez lui attribuer l'accès. |
| **Utilisateurs** | Les comptes autorisés (voir [plus bas](#donner-ou-retirer-laccès-à-une-application)). |

Après l'enregistrement, le **client secret** s'affiche **une seule fois** : copiez-le immédiatement (il est stocké
haché). Perdu ? Bouton **Régénérer le secret** sur la fiche de l'application (l'ancien cesse de fonctionner :
l'application doit être mise à jour).

### En ligne de commande

```bash
# Raccourci : redirect URI <url>/auth/callback, back-channel <url>/auth/backchannel-logout
make prod-app name="Application 1" id=app1 url=https://app1.mydomain.com

# Commande complète
make prod-console c='app:application:create "Application 1" --id=app1 \
    --home-url=https://app1.mydomain.com \
    --redirect-uri=https://app1.mydomain.com/auth/callback \
    --backchannel-logout-uri=https://app1.mydomain.com/auth/backchannel-logout'
```

Options : `--public` (client public), `--open-registration`, `--backchannel-logout-uri`, `--skip-if-exists` (ne fait
rien si le client ID existe déjà, pratique dans un script).

Tester l'URL de back-channel d'une application (l'utilisateur est réellement déconnecté de cette application) :

```bash
make prod-console c="app:application:test-backchannel-logout app1 alice@mydomain.com"
```

### Plusieurs environnements d'une même application

Déclarez une application par environnement (`app1`, `app1-staging`…) plutôt que de mélanger les redirect URIs :
chaque environnement a ainsi son propre secret et ses propres accès. Pour le développement local, une redirect URI
en `http://localhost:…` est acceptée.

## Personnaliser la page de connexion d'une application

`/admin` → **Applications** → éditer, section **Personnalisation de la page de connexion** :

| Champ | Détail |
|---|---|
| **Logo** | PNG, JPEG, WebP ou GIF, 2 Mo maximum, affiché sur 64 px de haut. SVG refusé (il peut contenir du script). |
| **Couleur principale** | Boutons et liens (`#2563eb` par défaut). |
| **Couleur de fond** | Fond de la page (`#f3f4f6` par défaut). |
| **Message d'accueil** | Sous le titre, 500 caractères maximum. Ex. « Bienvenue sur l'intranet de l'association ». |

La personnalisation s'applique aux pages de connexion, d'inscription et « accès refusé » quand l'utilisateur arrive
depuis l'application. Le bouton **Aperçu de la page de connexion** montre le résultat. Les logos sont stockés dans
le volume `oauth_uploads` (inclus dans `make prod-backup`).

## Gérer les utilisateurs

`/admin` → **Utilisateurs**. La liste se filtre par application et par statut (actif / bloqué).

| Action | Comment |
|---|---|
| **Créer un compte** | **Créer** : e-mail, nom, mot de passe initial, applications autorisées. Communiquez le mot de passe par un canal sûr ; la personne pourra le changer via « Mot de passe oublié ». |
| **Modifier le nom ou l'e-mail** | Éditer la fiche. Les applications doivent identifier l'utilisateur par son `id`, qui ne change jamais (voir [intégration](integrer-une-application.md#4-identifier-lutilisateur)). |
| **Changer le mot de passe** | Éditer → *Nouveau mot de passe* (laisser vide pour ne pas changer). Un nouveau code MFA sera demandé sur tous ses appareils. |
| **Désactiver le compte** | Décocher *Actif* : déconnexion immédiate de partout, connexion impossible. Recocher pour réactiver. |
| **Donner le rôle administrateur** | *Rôles* → *Administrateur*. |
| **Supprimer** | Supprime le compte et révoque ses jetons. Préférez la désactivation si vous voulez garder une trace. |

Les colonnes *Inscrit le* et *Dernière connexion* aident à repérer les comptes inutilisés.

En ligne de commande :

```bash
make prod-console c='app:user:create alice@mydomain.com'                    # mot de passe demandé
make prod-console c='app:user:create bob@mydomain.com --username=Bob --admin'
```

### Inscription libre

N'importe qui peut créer un compte via la page d'inscription, mais **un compte sans application ne donne accès à
rien**. Pour qu'une inscription donne accès directement à une application, cochez *Inscription ouverte* sur cette
application. La personne choisit uniquement un **pseudo unique** (pas de nom séparé) (3 à 30 caractères : lettres, chiffres, `.` `_` `-` ;
« Bob » et « bob » sont considérés identiques), modifiable ensuite par un administrateur. Les comptes créés avant
l'ajout du pseudo en ont reçu un automatiquement (partie locale de l'e-mail suivie de l'id, ex. `alice_12`).
L'adresse e-mail est vérifiée par le code envoyé à la première connexion. Les inscriptions sont
limitées à 5 par heure et par adresse IP.

### Importer les comptes d'une application existante

Pour une application qui gérait elle-même ses comptes, la commande `app:user:import` crée les comptes à partir d'un
fichier JSON exporté par l'application :

```json
[
  { "email": "alice@mydomain.com", "username": "alice", "password": "$2a$10$…" }
]
```

- **Mots de passe** : un hash **bcrypt** (`$2a$`, `$2b$`, `$2y$`, par exemple produit par `bcryptjs`, `password_hash()`
  ou `passlib`) est repris tel quel : l'utilisateur se connecte avec **son mot de passe habituel**, et le hash est
  mis à niveau à sa première connexion. Autre format ou champ absent : le compte est créé sans mot de passe
  utilisable, l'utilisateur passe par « Mot de passe oublié ».
- **Pseudo** : celui de l'application s'il respecte les règles, sinon corrigé (caractères interdits retirés, numéro
  ajouté s'il est déjà pris).
- **E-mail déjà présent** sur le serveur : le compte n'est pas modifié (mot de passe et pseudo conservés) ; seul
  l'accès à l'application est ajouté avec `--grant`.

```bash
docker compose --env-file .env.docker -f compose.yaml -f compose.prod.yaml cp users.json php:/tmp/users.json
make prod-console c='app:user:import /tmp/users.json --grant=app1 --dry-run'   # simulation
make prod-console c='app:user:import /tmp/users.json --grant=app1'
docker compose --env-file .env.docker -f compose.yaml -f compose.prod.yaml exec php rm /tmp/users.json
```

Le fichier contient des hash de mots de passe : supprimez-le partout après l'import. Côté application, rattachez
ensuite chaque compte local au premier login : retrouvé une seule fois par e-mail, il garde ensuite l'`id` (UUID)
du serveur comme clé ([Adapter une application](adapter-une-application.md#0-avant-de-commencer-tous-les-cas), point 5).

## Donner ou retirer l'accès à une application

Trois façons, au choix :

- **Depuis l'utilisateur** : `/admin` → Utilisateurs → éditer → *Applications autorisées*.
- **Depuis l'application** : `/admin` → Applications → éditer → *Utilisateurs* (pratique pour ajouter plusieurs
  personnes à la même application).
- **En ligne de commande** :
  ```bash
  make prod-grant email=alice@mydomain.com app=app1
  make prod-console c="app:access:grant alice@mydomain.com app1 --revoke"
  ```

L'accès donné est effectif à la prochaine connexion de l'utilisateur à l'application. **Retirer** un accès révoque
immédiatement ses jetons pour cette application et la prévient (back-channel logout) : une application conforme
ferme la session de l'utilisateur en quelques secondes, et au plus tard au renouvellement de son jeton (15 min).

Quand un utilisateur sans accès essaie de se connecter, il voit *« Vous n'avez pas accès à l'application X.
Contactez l'administrateur… »* : c'est le signal qu'il faut lui attribuer l'application.

## Révoquer un utilisateur

`/admin` → **Utilisateurs**, sur la liste ou la fiche :

| Action | Effet |
|---|---|
| **Déconnecter partout** | Ferme toutes ses sessions (y compris « rester connecté »), oublie ses appareils de confiance, révoque tous ses jetons et prévient ses applications (back-channel logout). Le compte reste actif : il peut se reconnecter (avec un code MFA). À utiliser après la perte d'un téléphone ou d'un ordinateur. |
| **Bloquer immédiatement** | Idem, et désactive le compte. À utiliser en cas de départ ou de compte compromis. |

En ligne de commande :

```bash
make prod-revoke email=alice@mydomain.com                                        # bloquer
make prod-console c="app:user:revoke alice@mydomain.com --logout-only"           # déconnecter partout
```

Vous ne pouvez pas bloquer votre propre compte.

## Ce que les utilisateurs font eux-mêmes

| Besoin | Où |
|---|---|
| Créer son compte | Lien « Créer un compte » sur la page de connexion |
| Voir ses applications | Portail : `https://oauth.mydomain.com/` |
| Mot de passe oublié / le changer | Lien « Mot de passe oublié ? » (e-mail avec un lien valable 1 h) |
| Ne plus saisir de code sur son ordinateur | Cocher « Faire confiance à cet appareil » (30 jours) |
| Se déconnecter de tout | Bouton de déconnexion du portail, ou depuis une application |

Un changement de mot de passe depuis le portail, un écran « Mes appareils » et d'autres fonctions en libre-service
sont prévus : voir la [feuille de route](../ROADMAP.md).

## Référence des commandes

| Commande Symfony | Rôle |
|---|---|
| `app:user:create <email> [--username=…] [--admin] [--password=…]` | Créer un utilisateur (pseudo déduit de l'e-mail si absent) |
| `app:user:import <fichier.json> [--grant=<client_id>] [--dry-run]` | Importer les comptes d'une application, mots de passe bcrypt conservés |
| `app:user:revoke <email> [--logout-only]` | Bloquer (ou seulement déconnecter partout) |
| `app:application:create <nom> [--id] [--home-url] [--redirect-uri]… [--backchannel-logout-uri] [--public] [--open-registration] [--skip-if-exists]` | Déclarer une application |
| `app:application:test-backchannel-logout <client_id> <email>` | Envoyer un logout token à l'application (test de son URL) |
| `app:access:grant <email> <client_id> [--revoke]` | Donner / retirer un accès |
| `league:oauth2-server:clear-expired-tokens` | Purger les jetons expirés (cron) |
| `mailer:test <email>` | Tester l'envoi d'e-mails |

| Raccourci `make` (production) | Équivalent |
|---|---|
| `make prod-admin email=…` | `app:user:create … --admin` |
| `make prod-app name=… id=… url=…` | `app:application:create` avec redirect URI `<url>/auth/callback` et back-channel `<url>/auth/backchannel-logout` |
| `make prod-grant email=… app=…` | `app:access:grant` |
| `make prod-revoke email=…` | `app:user:revoke` |
| `make prod-mailtest to=…` | `mailer:test` |
| `make prod-purge-tokens` | `league:oauth2-server:clear-expired-tokens` |
