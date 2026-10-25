# Tests

← [Documentation](README.md)

Le projet se teste à deux niveaux, tous automatisés et exécutés par l'intégration continue à chaque push.

| Niveau | Commande | Durée | Ce qui est vérifié |
|---|---|---|---|
| Tests fonctionnels (SQLite) | `make test` | ~10 s | 55 scénarios sur l'application Symfony (voir ci-dessous) |
| Mêmes tests sur PostgreSQL | `make test-pg` | ~15 s | idem, sur la vraie base, après les migrations |

## Tests fonctionnels (PHPUnit)

```bash
make test                          # tous les tests
make test c="--filter MfaTest"     # une classe
make test c="--filter testFullAuthorizationCodeFlow"
```

Sans Docker : `php bin/phpunit`. Les tests utilisent SQLite et une paire de clés RSA générée automatiquement
(`var/test-keys`) ; la base est recréée avant chaque test. Les e-mails ne partent pas : les codes MFA sont lus en
base.

| Fichier (`tests/Functional`) | Ce qui est couvert |
|---|---|
| `OAuthFlowTest` | Flux complet code → jetons → `/api/userinfo` → renouvellement ; page « accès refusé » ; révocation ; mauvais secret ; redirection après déconnexion limitée aux applications déclarées |
| `BackchannelLogoutTest` | Back-channel logout : applications prévenues lors d'un blocage (admin), d'un « Déconnecter partout » ou d'un retrait d'accès (seule l'application concernée), compte supprimé, logout token conforme à la spécification (signature RS256, `iss`, `aud`, `uid`, `events`, `typ`, durée de vie), nouvelles tentatives sans bloquer l'administration si l'application est en panne, commande de test, validation de l'URL |
| `ClientIntegrationTest` | Ce dont dépendent les applications : PKCE obligatoire pour les clients publics, rotation des refresh tokens, vérification du JWT avec la clé publique (RS256, `aud`, `exp`), scopes, authentification HTTP Basic, application désactivée |
| `MfaTest` | Envoi du code, code erroné ou expiré, anti-bruteforce (y compris depuis plusieurs IP), renvoi, appareils de confiance |
| `RegistrationTest` | Inscription, adresse déjà utilisée (« déjà inscrit »), pseudo déjà pris ou invalide, inscription ouverte ou non |
| `ResetPasswordTest` | Mot de passe oublié complet, lien à usage unique, expiration, comptes inconnus ou bloqués, limitation |
| `RevocationTest` | « Déconnecter partout », blocage, en-têtes de sécurité |
| `AdminTest` | Accès à l'admin, gestion des utilisateurs et applications, portail |
| `BrandingTest` | Personnalisation des pages par application, envoi de logo, validations |

### Sur PostgreSQL

```bash
make test-pg
```

Crée la base `app_test` dans le conteneur PostgreSQL de dev et y lance les mêmes tests. Sans Docker :
`DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/app_test?serverVersion=16" php bin/phpunit`.

## Tester à la main

Connectez une application à <http://localhost:8080> (voir
[Installation – développement](installation-developpement.md#4-connecter-une-application)). Les e-mails arrivent
dans Mailpit (<http://localhost:8025>).

## Intégration continue

`.github/workflows/ci.yml`, à chaque push et pull request :

| Job | Étapes |
|---|---|
| **Tests** | `composer validate` et `composer audit` (failles connues), lint du conteneur, des templates et du YAML, tests sur SQLite, migrations et tests sur PostgreSQL 16 |
| **Image Docker (prod)** | construction de l'image de production, taille, validation du Caddyfile, démarrage de la stack de production complète |

Dependabot (`.github/dependabot.yml`) propose chaque semaine les mises à jour de l'image de base, des dépendances
PHP et des actions GitHub ; la CI les valide avant fusion.

Les workflows `security.yml` et `dependency-review.yml` ajoutent respectivement un audit quotidien
de `composer.lock` (également sur les PR et push principaux) et un blocage des nouvelles dépendances
vulnérables dans les PR. `codeql.yml` analyse les workflows GitHub Actions ; **CodeQL ne prend pas
en charge PHP**. Réglages GitHub et checks à rendre obligatoires :
[Sécurité GitHub](securite-github.md).

## Écrire un test

Les tests fonctionnels héritent de `tests/Functional/FunctionalTestCase.php`, qui fournit :

- `createApplication()` / `createUser(applications: [...], roles: [...])` ;
- `login($email)` : connexion complète, code MFA compris ;
- `authorizeUrl()` : URL `/authorize` de l'application de test.

```php
public function testMonScenario(): void
{
    $application = $this->createApplication();
    $this->createUser(applications: [$application]);

    $this->client->request('GET', $this->authorizeUrl());
    $this->login();
    $this->client->followRedirect();

    self::assertResponseRedirects();
}
```
