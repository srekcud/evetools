# Spec #166 — Group Industry : requêtes de la distribution et de la liste des ventes

Statut : **brouillon**, en attente de validation par Sylvain (voir « 🚦 Questions pour Sylvain »).

## Contexte

L'issue signale un N+1 sur la distribution (`GET /group-industry/projects/{projectId}/distribution`) et la liste des ventes (`GET /group-industry/projects/{projectId}/sales`), restant de #39. Le tri du 2026-10-23 le classait « incertain » (non mesuré). Comptage établi depuis le code (branche `fix/group-group-industry`, 90f0618, Doctrine ORM 3.6.2) ; il sera confirmé par le test rouge.

Mappings en jeu : `GroupIndustryContribution::$member`, `GroupIndustryProjectMember::$user`, `GroupIndustrySale::$recordedBy` et `User::$mainCharacter` sont des associations côté propriétaire, chargées en proxy paresseux. `Character::$eveToken` est un OneToOne inverse : Doctrine le charge par une requête à chaque hydratation d'un `Character` (déjà noté dans `GroupIndustryContributionRepository::findByProjectForListing()` et `GroupIndustryProjectMemberRepository::loadUsersWithMainCharacter()`).

### Distribution

`GroupDistributionProvider::provide()` puis `GroupIndustryDistributionService::calculateDistribution()` :

| Étape | Requêtes |
|---|---|
| `GroupIndustryProjectRepository::find()` | 1 |
| `assertUserCanAccessProject()` : `findOneBy` sur les membres (lecteur non owner) | 1 |
| `GroupIndustrySaleRepository::findBy(['project'])` (seul `getTotalPrice()` est lu, pas `recordedBy`) | 1 |
| `GroupIndustryContributionRepository::findApprovedByProject()` (`findBy`, aucune jointure) | 1 |
| Par **membre distinct** ayant une contribution approuvée : `PilotName::of($member->getUser())` (`GroupIndustryDistributionService::buildMemberDistributions()`) charge le membre, son user, son main et le token du main | jusqu'à 4 |

Soit au plus **4 + 4 × M** requêtes (M membres contributeurs distincts) : 84 pour 20 membres, 164 pour 40. Le nombre ne dépend ni du nombre de contributions (la carte d'identité dédoublonne les membres) ni du nombre de ventes. Le N+1 existe, par membre.

### Liste des ventes

`GroupSaleCollectionProvider::provide()` :

| Étape | Requêtes |
|---|---|
| `find()` du projet | 1 |
| `assertUserCanAccessProject()` (lecteur non owner) | 1 |
| `GroupIndustrySaleRepository::findBy(['project'], ['soldAt' => 'DESC'])` | 1 |
| Par **enregistreur distinct** : `PilotName::of($sale->getRecordedBy())` (`mapToResource()`) charge le user, son main et le token du main | jusqu'à 3 |

Soit au plus **3 + 3 × R** requêtes (R enregistreurs distincts). Pas de requête par vente : 10 ou 20 ventes du même owner donnent le même nombre. Seuls l'owner et les admins enregistrent une vente (`GroupProjectAccessChecker::assertAdminOrOwner()`, #162), donc R reste petit. Le N+1 existe, par enregistreur, faible en pratique.

## Règles métier

- **R1 — Distribution bornée.** Le nombre de requêtes de la distribution ne dépend ni du nombre de membres, ni de contributions, ni de ventes. Proposition : `findApprovedByProject()` charge en une requête (fetch join) le membre, son user (jointure gauche), le main (gauche) et le token du main (gauche), sur le modèle de `findByProjectForListing()`. Le nom et l'unique appelant sont conservés.
- **R2 — Liste des ventes bornée.** Le nombre de requêtes de la liste des ventes ne dépend ni du nombre de ventes ni du nombre d'enregistreurs. Proposition : une méthode `GroupIndustrySaleRepository::findByProjectForListing()` (même tri `soldAt DESC`) qui joint à gauche `recordedBy`, son main et le token du main.
- **R3 — Valeurs inchangées.** Montants, parts, payouts et noms restent identiques : `null` pour un compte supprimé (user `null`, jointure gauche obligatoire), « Unknown » pour un user sans main (`PilotName::WITHOUT_MAIN_CHARACTER`), membre retiré toujours payé sur ses contributions approuvées.

## Exemples chiffrés

Comptage de toutes les requêtes SQL exécutées pendant `provide()` (sans filtre de table, comme `GroupContributionCollectionProviderTest::testQueryCountDoesNotGrowWithContributionsAndMembers`), lecteur membre accepté, gestionnaire d'entités vidé avant l'appel.

| # | Endpoint | Jeu A | Jeu B (doublé) | Attendu | Aujourd'hui (d'après le code) |
|---|---|---|---|---|---|
| E1 | distribution | 5 membres, 10 contributions approuvées, 4 ventes | 10 membres, 20 contributions, 8 ventes | même nombre de requêtes pour A et B | A ≈ 4 + 20, B ≈ 4 + 40 |
| E2 | distribution | 20 membres, 50 contributions, 10 ventes (jeu de l'issue) | 40, 100, 20 | même nombre pour A et B, au plus 5 | ≈ 84 et ≈ 164 |
| E3 | ventes | 4 ventes enregistrées par 2 admins | 8 ventes par 4 admins | même nombre, au plus 4 | ≈ 3 + 6 et ≈ 3 + 12 |
| E4 | ventes | 10 ventes par l'owner | 20 ventes par l'owner | même nombre | déjà égal (≈ 6) : non rouge, sert de garde-fou |
| E5 | distribution | membre au compte supprimé avec 1 000 000 ISK approuvés, owner 3 000 000, vente 4 400 000, frais 0 | — | payouts 1 100 000 et 3 300 000, nom `null` pour le compte supprimé | idem (couvert par `AccountDeletionServiceTest::testDistributionIsUnchangedAndTheDeletedAccountHasNoCharacterName`) |
| E6 | distribution | membre sans main avec une contribution approuvée | — | `characterName` « Unknown » | idem |
| E7 | ventes | vente enregistrée par un compte supprimé | — | `recordedByCharacterName` `null` | idem |

Les nombres « aujourd'hui » sont des majorants tirés du code ; le test rouge compare A et B sans figer un nombre exact, sauf les bornes « au plus » de E2 et E3 (projet + accès + une requête par lecture).

## Test qui compte les requêtes

Outil déjà présent, sans dépendance : le trait `tests/Integration/RecordsSqlQueries.php` (`sqlExecutedDuring()`, middleware `Symfony\Bridge\Doctrine\Middleware\Debug`), utilisé par `GroupContributionCollectionProviderTest` et `GroupMemberCollectionProviderTest`. Deux tests d'intégration :

- `tests/Integration/State/Provider/GroupIndustry/GroupDistributionProviderTest.php` : E1, E2 (ou seulement E1, Q2), E6 ;
- `tests/Integration/State/Provider/GroupIndustry/GroupSaleCollectionProviderTest.php` : E3, E4, E7.

Chaque test construit le provider à la main avec un `Security` bouchonné, comme `GroupContributionCollectionProviderTest::listContributionsOf()`. Ne pas reprendre son filtre `GROUP_INDUSTRY_TABLES_PATTERN` pour la comparaison A/B : il ignore `eve_tokens`, qui fait partie du N+1.

## Cas limites

- Lecteur owner : pas de requête d'accès, une de moins ; lecteur d'une même corporation non membre : quelques requêtes constantes (owner, mains, tokens). Sans effet sur la comparaison A/B.
- Lecteur contributeur : son membre et son user sont déjà en mémoire ; le fetch join ne doit pas en changer les valeurs.
- Jointure sur le membre dans `findApprovedByProject()` : un fetch join n'ajoute pas de ligne (relations à un seul côté), les sommes restent justes.
- Un membre retiré garde ses contributions approuvées dans la distribution (glossaire « Membre retiré »).

## Hors périmètre

- Duplication du contrôle d'accès : traitée par #162 (`GroupProjectAccessChecker::assertCanRead()`), livrée dans le même lot.
- Le `findBy` des ventes dans `calculateDistribution()` (une requête, pas de N+1).
- Pagination de la liste des ventes.

## Tests existants appelés à changer

Aucun attendu : `GroupIndustryDistributionServiceTest` bouchonne `findApprovedByProject()`, dont le nom est conservé ; `AccountDeletionServiceTest` construit `GroupSaleCollectionProvider` avec les mêmes dépendances.

## Glossaire

Termes existants : **Contribution**, **Payout**, **Membre retiré**, **Compte supprimé**. Aucun ajout.

## 🚦 Questions pour Sylvain

1. **Liste des ventes.** Le N+1 y est par enregistreur distinct (owner et admins), pas par vente. Corriger quand même par fetch join (R2, une méthode de dépôt) plutôt que fermer cette partie sur preuve (E4). Recommandation : corriger. OK ?
2. **Taille du jeu de test.** Comparer 5 contre 10 membres (E1) plutôt que 20 contre 40 (E2, jeu de l'issue) pour garder le test rapide ; même preuve. Recommandation : E1 seul. OK ?
3. **Borne absolue.** En plus de la comparaison A/B, figer un maximum (5 pour la distribution, 4 pour les ventes) comme `MAX_QUERIES` dans `GroupContributionCollectionProviderTest`. Recommandation : oui. OK ?
