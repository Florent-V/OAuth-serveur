# Feuille de route

Fonctionnalités envisagées pour les prochaines versions, par thème. L'ordre à l'intérieur d'un thème
reflète la priorité suggérée.

## Compte utilisateur (self-service)

- [ ] **Changer son mot de passe depuis le portail** (ancien mot de passe + code MFA), avec déconnexion des autres
  appareils en option.
- [ ] **Page « Mon compte »** : modifier son nom, voir ses applications, date de dernière connexion.
- [ ] **Changer d'adresse e-mail** avec confirmation sur la nouvelle adresse (lien) et notification sur l'ancienne.
- [ ] **Appareils et sessions actives** : liste des appareils de confiance / sessions (navigateur, IP, date),
  bouton « Déconnecter cet appareil » et « Déconnecter partout ».
- [ ] **Notification par e-mail** lors d'une connexion depuis un nouvel appareil.
- [ ] **Suppression de son compte** et **export de ses données** (RGPD).

## Sécurité et authentification

- [ ] **Application d'authentification (TOTP)** : Google Authenticator, Aegis… en alternative à l'e-mail
  (`scheb/2fa-totp`), avec **codes de secours** (`scheb/2fa-backup-code`).
- [ ] **Passkeys / WebAuthn** (connexion sans mot de passe, clé de sécurité).
- [ ] **MFA configurable par application** (ex. obligatoire à chaque fois pour l'admin ou une appli sensible).
- [ ] **Vérification des mots de passe compromis** (Have I Been Pwned, `NotCompromisedPassword`).
- [ ] **CAPTCHA** sur l'inscription et le mot de passe oublié (Cloudflare Turnstile, hCaptcha, ou ALTCHA auto-hébergé).
- [ ] **Liste d'IP / pays bloqués**, blocage temporaire d'un compte après trop d'échecs.
- [ ] **Politique de mot de passe** paramétrable (longueur, expiration optionnelle).

## Administration

- [ ] **Journal d'audit** : connexions (réussies / échouées), MFA, réinitialisations, changements d'accès par l'admin,
  consultable et filtrable dans `/admin`.
- [ ] **Invitations** : l'admin invite une personne par e-mail avec les applications déjà attribuées ; elle choisit
  son mot de passe via le lien.
- [ ] **Demandes d'accès** : depuis la page « Vous n'avez pas accès », bouton « Demander l'accès » → notification
  à l'admin → validation en un clic.
- [ ] **Fermer l'inscription libre** (option globale) : comptes créés uniquement par l'admin ou sur invitation.
- [ ] **Notification à l'admin** lors d'une nouvelle inscription.
- [ ] **Groupes** d'utilisateurs (ex. « Famille », « Équipe ») auxquels on attribue des applications.
- [ ] **Rôles par application** (ex. `app1: editor`) transmis dans le jeton et `/api/userinfo`.
- [ ] **Accès temporaires** (date d'expiration d'un accès).
- [ ] **Tableau de bord** : statistiques de connexions par application, utilisateurs actifs.
- [ ] **Import / export CSV** des utilisateurs et de leurs accès.

## Protocole (OAuth2 / OpenID Connect)

- [ ] **OpenID Connect complet** : `id_token`, `/.well-known/openid-configuration`, `/.well-known/jwks.json`
  → compatibilité « plug and play » avec les applications qui gèrent OIDC (Nextcloud, Grafana, Gitea, Outline,
  Portainer, Proxmox…).
- [x] **Déconnexion back-channel** (OIDC Back-Channel Logout) : les applications sont prévenues quand un
  utilisateur est révoqué (blocage, « déconnecter partout », accès retiré, suppression, mot de passe réinitialisé).
- [ ] **Identifiant de session `sid`** dans les logout tokens (avec OpenID Connect) : propager aussi la déconnexion
  volontaire d'un utilisateur à ses autres applications, appareil par appareil.
- [ ] **PKCE obligatoire pour tous les clients** (RFC 9700, OAuth 2.1), pas seulement les clients publics :
  protège aussi les clients confidentiels contre l'injection de code d'autorisation. Champ `pkceRequired` sur
  l'application (activé par défaut, y compris pour les applications existantes), case « Autoriser sans PKCE
  (compatibilité) » dans l'admin et option de `app:application:create` pour les logiciels tiers qui ne le gèrent pas.
  Contrôle de `code_challenge` (méthode `S256` uniquement) sur `/authorize` **avant** la connexion de l'utilisateur
  (listener `kernel.request`, pas `AuthorizationRequestSubscriber` qui intervient après) ; erreur `invalid_request`.
  Avant déploiement : vérifier que les applications enregistrées envoient déjà un `code_challenge`.
- [ ] **Introspection** (RFC 7662) et **révocation** (RFC 7009) de jetons, pour des API qui recevraient des jetons
  de clients tiers (inutile avec l'architecture BFF recommandée).
- [ ] **CORS** configurable par application (pour les SPA sans backend qui appellent `/token` depuis le navigateur ;
  en attendant, passer par un petit backend, voir docs/integrer-une-application.md).
- [ ] **Consentement optionnel** par application (pour des applications tierces).
- [ ] **Forward auth** (Caddy `forward_auth`, Traefik) : protéger une application qui ne gère pas OAuth2
  directement au niveau du reverse proxy.
- [ ] Grant **client_credentials** (communication entre applications, sans utilisateur).

## Interface

- [ ] **Traductions** (anglais) et sélection de la langue.
- [ ] **Thème global** personnalisable (logo et couleurs par défaut du serveur, pas seulement par application).
- [ ] **Personnalisation des e-mails** par application (logo, couleurs).
- [ ] Mode sombre sur l'administration aligné sur les pages publiques.

## DevOps et exploitation

- [ ] **Publication de l'image** sur un registre (GitHub Container Registry) depuis la CI, avec tags de version
  et signature (cosign).
- [ ] **Scan de vulnérabilités** de l'image (Trivy) et des dépendances (`composer audit`) en CI.
- [ ] **Envoi asynchrone des e-mails** (Symfony Messenger + worker) pour ne pas ralentir les réponses si Brevo
  est lent.
- [ ] **Redis** pour les sessions, le cache et les limiteurs (nécessaire pour faire tourner plusieurs conteneurs).
- [ ] **Métriques Prometheus** (FrankenPHP expose déjà les siennes) et tableau de bord Grafana.
- [ ] **Sauvegardes automatiques chiffrées** vers un stockage externe (S3, Backblaze) avec rotation.
- [ ] **Endpoint de santé** applicatif (`/health`) vérifiant la base de données.
- [ ] **Logs structurés JSON** et envoi vers Loki / un collecteur.
- [ ] **Déploiement sans interruption** (blue/green ou `docker rollout`).
