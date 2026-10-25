# Protection de main, dépendances et analyse de sécurité

← [Documentation](README.md)

## Analyse du dépôt et périmètre

Le dépôt public `Florent-V/OAuth-serveur` utilise `main` comme branche par défaut.
L'application est en PHP/Symfony, avec des dépendances Composer verrouillées dans
`composer.lock`. Il n'y a pas de manifeste npm à maintenir. Les fichiers JavaScript
de `public/bundles/` sont générés par les dépendances et ignorés par Git.

| Surface | Protection retenue | Limite |
|---|---|---|
| Régressions applicatives | CI existante : SQLite, PostgreSQL, migrations, lints Symfony et démarrage Docker | Ne remplace pas une revue du protocole OAuth/MFA |
| Dépendances PHP | Dependabot, Dependency Review et audit Composer quotidien du lockfile, dépendances de développement comprises | Détection des vulnérabilités publiées, pas des erreurs dans notre code |
| Workflows GitHub Actions | CodeQL `actions`, actions épinglées par SHA, permissions minimales et absence de secrets de production | Ne couvre pas le code PHP |
| Images et système | Mises à jour Docker proposées par Dependabot et validation du build | Pas de scanner de CVE des images installé par cette configuration |
| Changements sensibles | PR obligatoires, checks requis et CODEOWNERS | Les règles doivent être activées sur GitHub |

**CodeQL ne prend pas en charge PHP.** Le workflow `codeql.yml` analyse les workflows
GitHub Actions avec `security-extended`, sur les PR, les push de la branche principale
et chaque semaine. Il n'installe ni n'exécute l'application.

Pour une analyse statique de sécurité du code PHP, la couche complémentaire recommandée
est Semgrep avec des règles PHP/Symfony, exécuté localement dans la CI sans transmission
du code à un service tiers. Prévoir des règles et une version épinglées, une première
analyse pour qualifier les faux positifs, puis un blocage des nouvelles alertes fiables.
Cette couche PHP n'est **pas** installée ici ; ne pas interpréter un résultat CodeQL vert
comme une validation de sécurité du serveur OAuth.

## 1. Activer la sécurité des dépendances sur GitHub

Dans le dépôt, ouvrir **Settings → Advanced Security** (ou **Code security and analysis**,
selon l'interface), puis activer :

1. **Dependency graph** : nécessaire pour l'inventaire et Dependency Review.
2. **Dependabot alerts** : notifications lorsque GitHub identifie une dépendance vulnérable.
3. **Dependabot security updates** : PR de correction dès qu'un correctif compatible est disponible.
4. **Grouped security updates**, si l'option est proposée, pour les écosystèmes non groupés par le YAML.
5. **Secret scanning** et **Push protection**, si disponibles : bloquer les secrets avant publication.

Les PR de mise à jour de versions sont définies par `.github/dependabot.yml` ; elles
commencent une fois le fichier présent sur la branche par défaut. Les mises à jour
de sécurité nécessitent **aussi** les options ci-dessus.

| Écosystème | Versions | Sécurité |
|---|---|---|
| Composer | Lundi à 07:00, heure de Paris ; maximum 5 PR de versions ouvertes ; correctifs/mineures Symfony groupés | Groupe dédié aux correctifs de sécurité Composer, indépendant des groupes de versions |
| GitHub Actions | Même horaire ; maximum 5 PR ; correctifs/mineures groupés, majeures séparées | Alertes/correctifs selon les avis et la prise en charge GitHub |
| Docker | Même horaire ; maximum 3 PR ; revue des changements d'images | Ne pas compter sur Dependabot pour détecter toutes les CVE du système |

Les limites de PR ci-dessus concernent les mises à jour de **versions**, pas le quota
distinct des PR de sécurité. Les correctifs de sécurité ne doivent pas attendre le lundi.
Ne pas ajouter de `target-branch` alternatif ni de cooldown aux correctifs de sécurité.

Symfony reste dans la contrainte `7.4.*` du projet : une migration majeure est un travail
explicite, pas une mise à jour automatique. Les autres majeures restent visibles en PR
séparées ; aucun `ignore` global ne les masque. Si une vulnérabilité n'a pas de correctif
dans les contraintes autorisées, traiter manuellement la montée de version et ne pas
désactiver l'audit pour retrouver une CI verte.

Le `Dockerfile` compose son image FrankenPHP à partir de plusieurs `ARG` ; Compose
utilise aussi des variables de version. Vérifier les journaux Dependabot et les PR
effectivement générées : cette configuration ne garantit pas la résolution de ces tags.
Maintenir explicitement les versions FrankenPHP/PHP/PostgreSQL non résolues, reconstruire
avec `--pull`, puis prévoir un scan Trivy ou Grype de l'image finale pour les CVE système.
Une PR Docker ou un build vert ne constitue pas ce scan.

Le workflow `security.yml` audite `composer.lock` tous les jours à **06:23 UTC**, même sans
changement du code, ainsi que sur les PR et push principaux. Il fonctionne sans installer
les dépendances ni exécuter leurs plugins/scripts. Dependency Review bloque une PR qui
introduit une vulnérabilité connue de sévérité **low ou supérieure** ; l'audit Composer
couvre aussi les vulnérabilités déjà présentes.

## 2. Activer CodeQL sans double configuration

Fusionner `.github/workflows/codeql.yml` sur `main`. Dans
**Settings → Advanced Security → Code scanning**, utiliser la configuration **Advanced**
basée sur ce workflow ; ne pas générer un second workflow depuis l'assistant.
Si **Default setup** est déjà actif, le désactiver avant d'utiliser la configuration avancée.
Consulter ensuite **Actions → CodeQL** et **Security → Code scanning**.

La seule langue configurée est **GitHub Actions** (`actions`), pas `php`. L'analyse
hebdomadaire est lancée le mardi à **06:41 UTC**. Les horaires GitHub peuvent être décalés
par la charge de la plateforme ; les tâches planifiées tournent sur la branche par défaut.
Un dépôt public inactif peut voir ses tâches planifiées désactivées : surveiller leurs exécutions.

**Attention : un check CodeQL réussi signifie que l'analyse a fonctionné, pas qu'il
n'existe aucune alerte.** Pour bloquer les alertes, ajouter la règle de code scanning
décrite ci-dessous lorsqu'elle est disponible et examiner les alertes dans Security.

## 3. Protéger main avec un ruleset

Commencer par pousser ces fichiers sur une branche et ouvrir une PR vers `main`. Laisser
les nouveaux workflows s'exécuter une première fois : GitHub doit connaître leurs noms
avant de pouvoir les sélectionner comme checks requis. Ne pas mettre de filtre `paths`
sur ces workflows obligatoires, sinon certaines PR resteraient bloquées en attente.

Dans **Settings → Rules → Rulesets → New ruleset → New branch ruleset** :

| Réglage | Valeur recommandée |
|---|---|
| Ruleset name | `main-protection` |
| Enforcement status | `Active` |
| Target branches | `Include default branch` (actuellement `main`) |
| Bypass list | Vide en fonctionnement normal, y compris pour les administrateurs et Dependabot |
| Restrict deletions | Activé |
| Block force pushes | Activé |
| Require a pull request before merging | Activé |
| Require conversation resolution before merging | Activé |
| Require status checks to pass | Activé, sélectionner les checks ci-dessous |
| Require branches to be up to date before merging | Activé |
| Require linear history | Activé, avec squash merge |

Ne pas activer **Restrict updates** sans besoin spécifique : cela peut empêcher les fusions
normales. Ne pas activer la merge queue sans ajouter le déclencheur `merge_group` aux
workflows requis ; cette configuration utilise les PR classiques.

Sélectionner les checks suivants, avec **GitHub Actions** comme source attendue lorsque
l'interface le permet ; utiliser les noms réellement affichés après la première exécution :

| Check | Rôle |
|---|---|
| `Tests (PHP 8.4, PostgreSQL 16)` | Tests, migrations, lints et audit existant |
| `Image Docker (prod)` | Image et démarrage de production |
| `Composer audit (lockfile)` | Audit indépendant de l'installation et de l'application |
| `Dependency Review` | Nouvelles dépendances vulnérables dans une PR |
| `CodeQL (Actions)` | Bonne exécution de l'analyse des workflows |

Si **Require code scanning results** est disponible, l'activer pour l'outil **CodeQL**
et bloquer les alertes de sécurité **High/Critical** ainsi que les alertes de qualité
**Errors**. C'est complémentaire au check d'exécution. Si cette option n'est pas proposée,
la revue des alertes CodeQL reste manuelle : le simple check ne bloque pas leur présence.

### Approbations : éviter de bloquer un dépôt maintenu seul

Le fichier `.github/CODEOWNERS` attribue les workflows, dépendances, Docker et le code
d'authentification à `@Florent-V`. Il sert à demander une revue des PR d'autres contributeurs,
dont Dependabot, mais ne constitue pas une règle de blocage à lui seul.

- **Mainteneur seul** : laisser le nombre d'approbations obligatoires à **0** et ne pas
  cocher **Require review from Code Owners**. Les PR, checks verts et conversations
  résolues restent obligatoires. L'auteur ne peut pas approuver sa propre PR.
- **Au moins deux mainteneurs** : ajouter le second compte avec accès en écriture aux
  entrées CODEOWNERS, exiger **1 approbation**, activer **Require review from Code Owners**,
  **Dismiss stale pull request approvals when new commits are pushed** et
  **Require approval of the most recent reviewable push**.

Dans **Settings → General → Pull Requests**, autoriser **Squash merging**, désactiver
les merge commits si l'historique linéaire est requis, et activer la suppression automatique
des branches fusionnées. Ne pas mettre en place d'auto-merge généralisé pour un serveur
d'authentification : relire les notes de version et les changements de lockfile.

## 4. Réduire les droits de GitHub Actions

Dans **Settings → Actions → General** :

1. Choisir **Allow select actions and reusable workflows**, autoriser les actions GitHub
   nécessaires et ajouter `shivammathur/setup-php`, `ramsey/composer-install`,
   `docker/setup-buildx-action` et `docker/build-push-action`. Autoriser aussi
   `github/codeql-action` et `actions/dependency-review-action` si la politique sélectionnée
   ne les inclut pas déjà.
2. Dans **Workflow permissions**, choisir **Read repository contents and packages permissions**.
3. Décocher **Allow GitHub Actions to create and approve pull requests**. Dependabot
   utilise sa propre intégration : aucun PAT ni secret supplémentaire n'est nécessaire.
4. Exiger l'approbation des workflows des contributeurs externes dans
   **Fork pull request workflows from outside collaborators** ; choisir tous les
   contributeurs externes si cette option est disponible.

Les workflows utilisent des SHA complets, mis à jour par Dependabot, et checkout ne
persiste pas le jeton Git. Seul le job CodeQL reçoit `security-events: write` pour publier
ses résultats. Aucun `pull_request_target` ni secret de production n'est nécessaire.
Les PR Dependabot doivent passer exactement les mêmes tests que les autres.

Les secrets de production doivent rester sur le serveur. Si un déploiement automatisé
est ajouté plus tard, utiliser un environment `production` limité à `main`, avec ses
propres permissions et validations ; ne pas donner ces secrets aux jobs de PR.

## 5. Exploitation et vérification initiale

Après fusion et activation des options GitHub :

1. Vérifier une exécution réussie des workflows CI, Security et CodeQL sur `main`.
2. Ouvrir une PR de modification documentaire : vérifier que les cinq checks requis
   s'exécutent et que la fusion est impossible tant qu'ils ne sont pas tous verts.
3. Vérifier que `main` ne peut recevoir ni push direct ordinaire, ni force-push, ni suppression.
4. Dans **Insights → Dependency graph → Dependabot**, vérifier chaque écosystème et ses
   journaux ; dans **Security → Dependabot**, vérifier l'état des alertes.
5. Activer les notifications de sécurité du dépôt et vérifier les échecs des exécutions
   planifiées. Objectif interne : trier une alerte critique/haute sous 24 h, corriger ou
   mitiger sous 48 h ; traiter les alertes moyennes/basses lors de la maintenance hebdomadaire.

Pour une PR Dependabot : lire l'avis de sécurité/changelog, examiner `composer.lock`
et les changements de plugins/scripts, attendre les checks, puis fusionner manuellement.
Une mise à jour fusionnée ne met pas la production à jour : reconstruire et redéployer,
avec sauvegarde et vérification du parcours connexion/MFA/OAuth.

Cette stratégie ne remplace ni un audit de sécurité du code applicatif ni un plan de réponse
aux incidents. Une clé divulguée doit être révoquée et remplacée, pas seulement supprimée de Git.

## Références

- [Langages pris en charge par CodeQL, dont GitHub Actions et exclusion de PHP](https://docs.github.com/en/code-security/concepts/code-scanning/codeql/codeql-code-scanning)
- [Options de configuration Dependabot](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
- [Dependency Review](https://github.com/actions/dependency-review-action)
- [Rulesets](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets)
