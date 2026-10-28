# Spec #170 — Rôles entre admins, frais par défaut à la création, characterId absent

> Statut : **brouillon**. Le lot couvre les rôles entre admins (R1 à R5 : kick, rétrogradation, 403), les frais par défaut à la création (R6 à R8, exemples F1 à F8) et `characterId: null` (R9). Q2 (frais) est **tranchée par Sylvain le 2026-10-29** : option (b), voir « Décisions de Sylvain ». Q1, Q3 et Q4 : recommandations retenues.

## Contexte

L'audit du moteur industrie (2026-10-22) relève quatre écarts dans Group Industry. État vérifié dans le code au 2026-10-26 :

| Puce de l'issue | Encore fautif ? | Constat dans le code |
|---|---|---|
| Un admin peut rétrograder ou exclure un autre admin | **Oui, en partie** | La promotion en admin est déjà réservée au propriétaire : `UpdateGroupMemberProcessor::process()` répond 403 « Only the project owner can promote members to admin ». En revanche, rien ne regarde le rôle de la cible : un admin passe un autre admin en `member` (PATCH `role: member`), ou le retire par un kick (`KickGroupMemberProcessor::process()`, qui ne refuse que le propriétaire et soi-même). |
| Frais de 3,6 % codés en dur à la création | **Oui, mais pas « non configurables »** | `CreateGroupProjectProcessor::process()` remplace un frais absent par `?? 3.6` (courtage et taxe de vente, `CreateGroupProjectProcessor.php:73-74`). La même valeur est recopiée en défaut de `CreateProjectData` (`:26-27`), de `GroupIndustryProject` (`:58`, `:61`) et de `GroupIndustryProjectResource` (`:138`, `:140`). `NewProjectModal.vue` n'envoie aucun frais (aucune occurrence de `brokerFeePercent` ni `salesTaxPercent` dans ce composant) : tout projet créé depuis l'interface démarre à 3,6 % / 3,6 %, quels que soient les réglages Industry du propriétaire (`IndustryUserSettings::$brokerFeeRate`, `$salesTaxRate`, en fraction). Les frais restent modifiables après la création : PATCH via `UpdateGroupProjectProcessor` (`:80-85`), refusé sur un projet terminé (#152, `:67`), avec un formulaire dans `GroupIndustryDetail.vue` (`:155-156`, `:170-171`). |
| Un `characterId` absent est remplacé par `0` | **Oui** | `GroupIndustryResourceMapper::memberToResource()` renvoie `CHARACTER_ID_WITHOUT_MAIN_CHARACTER` (= 0) pour un utilisateur sans personnage principal. Il renvoie déjà `null` pour un compte supprimé (#53). |
| Barème de location de ligne jamais appliqué | Hors périmètre | Suivi dans #45. |

Côté interface, `MemberTable.vue::canManage()` affiche Promote, Demote et Kick à tout admin, sur toute ligne sauf celle du propriétaire et la sienne. Un admin voit donc un bouton Promote qui échoue en 403, et des boutons Demote et Kick sur les autres admins, qui réussissent aujourd'hui.

## Règles métier

### Rôles entre admins

- **R1 — Seul le propriétaire agit sur un admin.** Un admin non propriétaire qui vise un membre de rôle `admin` reçoit 403 « Only the project owner can manage an admin », qu'il s'agisse d'un changement de rôle, d'une acceptation ou d'un kick. Rien n'est modifié, aucun flush, aucun événement Mercure `member_left`.
- **R2 — Ce qu'un admin garde.** Sur un membre de rôle `member`, l'admin accepte une demande `pending`, retire un membre par un kick et laisse le rôle à `member`. La promotion en admin reste réservée au propriétaire (403, inchangé).
- **R3 — Ordre des refus.**
  - PATCH : 401, 404 projet, 403 « Only project owner or admin can perform this action », 404 membre, 400 « Cannot modify the project owner », puis R1 (403), puis la validation du corps (400 rôle invalide, 403 promotion, 400 statut).
  - DELETE : 401, 404 projet, 403 non admin, 404 membre, 400 « Cannot kick the project owner », 400 « Cannot kick yourself, use leave instead », puis R1 (403).
- **R4 — Une seule règle, au même endroit.** La règle R1 rejoint `GroupProjectAccessChecker` (méthode proposée : `assertCanManageMember(User, GroupIndustryProject, GroupIndustryProjectMember)`), appelée par les deux processors. On reste ainsi dans la ligne de #162.
- **R5 — Interface alignée** (sous réserve de Q4). Dans `MemberTable.vue`, seul le propriétaire voit Promote, ainsi que Demote et Kick sur une ligne admin. Un admin ne voit Kick que sur une ligne de rôle `member`. Aucun élément visuel n'est ajouté, des boutons sont seulement masqués : pas de maquette. `MemberTable.vue` ne reçoit aujourd'hui que la prop `isAdmin` (valeur `isOwnerOrAdmin` de `GroupIndustryDetail.vue`) : distinguer le propriétaire d'un admin demande une prop de plus (ou `myRole`), détail d'implémentation.

### Frais par défaut à la création

- **R6 — Une valeur saisie est gardée.** Un `brokerFeePercent` ou un `salesTaxPercent` envoyé à la création est gardé tel quel, 0 compris. Seul un frais absent (`null`) reçoit une valeur par défaut. C'est déjà le cas (`$data->brokerFeePercent ?? 3.6`, `CreateGroupProjectProcessor.php:73`).
- **R7 — Défaut d'un frais absent : les réglages Industry de l'utilisateur qui crée le projet.** Modèle : `CreateProjectProcessor::sellTaxRate()` (`CreateProjectProcessor.php:133-140`), qui lit `$this->settingsRepository->findOneBy(['user' => $user])` (`IndustryUserSettingsRepository`), puis `$settings?->getBrokerFeeRate() ?? EveConstants::DEFAULT_BROKER_FEE_RATE` et `$settings?->getSalesTaxRate() ?? EveConstants::DEFAULT_SALES_TAX_RATE`. `CreateGroupProjectProcessor` fait la même lecture, pour l'utilisateur connecté (le propriétaire du projet, `createProject($user, …)`), **un frais à la fois** (le modèle additionne les deux, ici on garde chacun), puis convertit la fraction en pourcents : `rate × 100`, arrondi à 4 décimales (`0.036 × 100` vaut `3.5999999999999996` en `float`).
  - **Réglages présents** : `getBrokerFeeRate()` et `getSalesTaxRate()` donnent le défaut de chaque frais absent.
  - **Aucun réglage** (aucune ligne `IndustryUserSettings` pour l'utilisateur) : repli sur `EveConstants::DEFAULT_BROKER_FEE_RATE` et `DEFAULT_SALES_TAX_RATE` (`EveConstants.php:12-13`, 0,036 chacune), donc 3,6 % / 3,6 %. **Ce repli est une valeur par défaut documentée d'evetools, pas une donnée ESI manquante remplacée par une valeur plausible** : c'est la valeur « compétences au niveau 0 » (commentaires de `IndustryUserSettings.php:36`, `:40`), déjà utilisée par Profit Margins, Buy vs Build, le scanner et le pivot (`ProfitMarginProvider.php:59-60`, `BuyVsBuildProvider.php:52-53`, `BatchScanProvider.php:59-60`, `PivotAdvisorProvider.php:51-52`). La règle « jamais de valeur par défaut sur une donnée manquante » ne s'applique pas : le réglage n'est pas une mesure, il se définit, et il est lu dans le projet comme tel.
  - **Réglages « partiels » : le cas n'existe pas.** Les deux colonnes sont non nulles (`IndustryUserSettings.php:37-38`, `:41-42`, getters `float` non nullables `:86-89`, `:97-100`) et la ligne est unique par utilisateur (`:15`). Une ligne porte donc toujours les deux taux ; un taux jamais modifié vaut 0,036. Le seul « partiel » possible est un **corps de requête** qui n'envoie qu'un des deux frais (F6) : le frais envoyé est gardé (R6), l'autre prend son défaut.
  - Un réglage à 0 est un réglage (F5), pas une absence.
  - Le front ne change pas : `NewProjectModal.vue` n'envoie toujours aucun frais, le propriétaire corrige ensuite dans le formulaire de `GroupIndustryDetail.vue`.
- **R8 — Plus de littéral.** `3.6` n'apparaît plus dans `src/` hors `EveConstants` : ni dans `CreateGroupProjectProcessor` (`:73-74`), ni dans `CreateProjectData` (`:26-27`), ni dans `GroupIndustryProject` (`:58`, `:61`), ni dans `GroupIndustryProjectResource` (`:138`, `:140`). Piège : ces défauts sont des expressions constantes, et `EveConstants::DEFAULT_BROKER_FEE_RATE * 100` donnerait `3.5999999999999996`, pas `3.6`. La dérivation en pourcents garde donc le même arrondi à 4 décimales que R7 (ou le défaut est retiré là où aucun appelant ne s'y fie). Le moment de lecture est la création : un projet garde ses frais quand les réglages changent ensuite.

### characterId d'un membre

- **R9 — Pas d'identifiant inventé** (selon Q3). Un membre dont l'utilisateur n'a pas de personnage principal est sérialisé avec `characterId: null`, comme un compte supprimé. `characterName` reste « Unknown » (`PilotName::of()`, inchangé). La constante `CHARACTER_ID_WITHOUT_MAIN_CHARACTER` disparaît. Le contrat de type ne change pas : `GroupIndustryMemberResource::$characterId` est déjà `?int`, `GroupMember.characterId` est déjà `number | null` (`stores/group-industry/types.ts`). `useEveImages::getCharacterPortraitUrl()` rend l'icône de repli pour `null`, alors qu'avec 0 il demande `/characters/0/portrait` au serveur d'images et dépend de `onImageError`.

## Exemples chiffrés

### Rôles (R1 à R3)

Projet `Sabre batch` `in_progress`. P = propriétaire, A et B = admins `accepted`, M = membre `accepted` de rôle `member`, Q = membre `pending` de rôle `member`.

| # | Acteur | Requête | Aujourd'hui | Attendu |
|---|---|---|---|---|
| R-E1 | A | PATCH B `{role: member}` | 200, B passe `member` | 403 « Only the project owner can manage an admin » ; B reste `admin` |
| R-E2 | A | DELETE B (kick) | B passe `removed`, `member_left` publié | 403 ; B reste `accepted` `admin`, aucun événement |
| R-E3 | A | PATCH B `{status: accepted}` | 400 « Only pending members can be accepted » | 403 (R1 avant la validation du corps) |
| R-E4 | A | PATCH A `{role: member}` (lui-même) | 200, A passe `member` | 403 (Q1) |
| R-E5 | A | DELETE A (lui-même) | 400 « Cannot kick yourself, use leave instead » | inchangé |
| R-E6 | P | PATCH B `{role: member}` | 200, B passe `member` | inchangé (garde-fou) |
| R-E7 | P | DELETE B | B passe `removed`, `member_left` publié | inchangé (garde-fou) |
| R-E8 | A | PATCH M `{role: admin}` | 403 « Only the project owner can promote members to admin » | inchangé (garde-fou) |
| R-E9 | A | DELETE M | M passe `removed` | inchangé (garde-fou, `testAdminSoftRemovesMemberWithoutContribution`) |
| R-E10 | A | PATCH Q `{status: accepted}` | 200, Q passe `accepted` | inchangé (garde-fou) |
| R-E11 | M | PATCH B `{role: member}` | 403 « Only project owner or admin can perform this action » | inchangé |
| R-E12 | A | PATCH P ou DELETE P | 400 « Cannot modify the project owner » / « Cannot kick the project owner » | inchangé |

### Frais par défaut (R6 à R8)

| # | Réglages Industry de l'utilisateur | Corps de la création | Aujourd'hui (courtage / taxe) | Attendu (courtage / taxe) |
|---|---|---|---|---|
| F1 | **absents** : aucun `IndustryUserSettings` | sans frais | 3,6 / 3,6 | 3,6 / 3,6 (repli sur `EveConstants`, R7) |
| F2 | **présents** : `brokerFeeRate` 0,015, `salesTaxRate` 0,045 | sans frais | 3,6 / 3,6 | 1,5 / 4,5 |
| F3 | présents : `brokerFeeRate` 0,036, `salesTaxRate` 0,036 (valeurs par défaut de la ligne) | sans frais | 3,6 / 3,6 | 3,6 / 3,6 exactement (`assertSame(3.6, …)` : 0,036 × 100 vaut 3,5999999999999996 en float, d'où l'arrondi à 4 décimales) |
| F4 | présents : 0,015 / 0,045 | `brokerFeePercent` 2,0, `salesTaxPercent` 0 | 2,0 / 0 | 2,0 / 0 (R6, les réglages ne servent pas) |
| F5 | présents : `brokerFeeRate` 0, `salesTaxRate` 0,036 | sans frais | 3,6 / 3,6 | 0 / 3,6 (un réglage à 0 est un réglage, pas une absence) |
| F6 | présents : `brokerFeeRate` 0,014234, `salesTaxRate` 0,045 | `salesTaxPercent` 3,0 seul (corps partiel) | 3,6 / 3,0 | 1,4234 / 3,0 |
| F7 | absents | `brokerFeePercent` 0 seul | 0 / 3,6 | 0 / 3,6 (0 saisi gardé, la taxe prend le repli) |
| F8 | présents : 0,015 / 0,045 | `brokerFeePercent` 2,0 seul | 2,0 / 3,6 | 2,0 / 4,5 (le frais absent lit son réglage) |

Réglages « partiels » : aucune ligne de tableau, le cas n'existe pas (R7, colonnes non nulles). F6 et F8 couvrent le corps partiel.

### characterId (R9)

| # | Membre | Aujourd'hui | Attendu |
|---|---|---|---|
| C1 | Utilisateur avec personnage principal 2112000001 | `characterId` 2112000001 | inchangé |
| C2 | Utilisateur sans personnage principal | `characterId` 0, `characterName` « Unknown » | `characterId` `null`, `characterName` « Unknown » |
| C3 | Compte supprimé (#53) | `characterId` `null`, `characterName` `null` | inchangé |

## Cas limites

- **Membre `pending` qui était admin.** Impossible : `GroupIndustryProjectMember::requestRejoin()` remet le rôle à `member`. Un admin peut donc toujours accepter une demande `pending`.
- **Admin retiré.** Un admin `removed` n'est plus admin pour `assertAdminOrOwner()` (`GroupProjectAccessCheckerTest::testRemovedAdminCanNoLongerAdministrate`). R1 vise le rôle de la cible, quel que soit son statut : un admin `removed` ne peut pas être visé par un autre admin.
- **Admin qui veut redevenir simple membre.** Avec Q1 = oui, il demande au propriétaire ou quitte le projet. L'interface ne lui propose déjà rien sur sa propre ligne (`canManage(member) && !isCurrentUser(member)`).
- **Frais sur un projet terminé.** Le PATCH des frais reste refusé (#152, inchangé). R7 ne joue qu'à la création.
- **Réglages modifiés après la création.** Le projet garde ses frais : le défaut est lu une seule fois, à la création.
- **Changement visible.** Un joueur aux frais réglés voit désormais ses réglages, et non plus 3,6 %, sur ses nouveaux projets. Les projets existants gardent leurs frais (aucune migration, aucune revalorisation).
- **Précision.** L'arrondi à 4 décimales du pourcentage coupe un réglage qui aurait plus de 6 décimales en fraction ; aucun garde-fou de précision n'existe sur `IndustryUserSettings` (écriture bornée à `max(0.0, …)`, `UpdateUserSettingsProcessor.php:63`).

## Hors périmètre

- Le barème de location de ligne, jamais appliqué (#45).
- Saisir les frais dans `NewProjectModal.vue`. Ce serait un changement visuel, avec maquette ; le formulaire d'édition du projet existe déjà.
- Le nom de repli « Unknown » (`PilotName::of()`, `GroupIndustryResourceMapper::projectToResource()` pour `ownerCharacterName`). C'est un libellé d'affichage, déjà traité par le front (`usePilotName`).
- Le kick d'un membre déjà `removed` (déjà hors périmètre de #87).
- `GroupIndustryDistributionService::calculateDistribution()` applique le courtage et la taxe de vente au revenu total, quel que soit le lieu de chaque vente. Le glossaire (« Marge ») ne compte aucun frais pour un contrat. C'est à vérifier dans une issue séparée.
- Aucune migration : la colonne des frais n'a pas de défaut en base, et `IndustryUserSettings` existe déjà. Aucune dépendance.

## Tests existants appelés à changer

- `GroupIndustryResourceMapperTest::testMemberToResourceHandlesNullMainCharacter` : attend `0`, attendra `null` (C2).
- `Integration\…\GroupMemberCollectionProviderTest::testPendingMemberAndMemberWithoutMainCharacter` : attend `characterId` `0` pour « Unknown », attendra `null`.
- `KickGroupMemberProcessorTest` : les tests existants visent un membre de rôle `member` (`acceptedMember()`) et restent verts. On ajoute R-E2, R-E5 et R-E7.
- `UpdateGroupMemberProcessor` n'a aucun test : création de `UpdateGroupMemberProcessorTest` (R-E1, R-E3, R-E4, R-E6, R-E8, R-E10 à R-E12).
- Frais (R6 à R8) : `CreateGroupProjectProcessor` reçoit `IndustryUserSettingsRepository` (nouveau paramètre de constructeur). Les huit tests de `CreateGroupProjectProcessorTest` (`:60`, `:69`, `:82`, `:134`, `:154`, `:175`, `:196`, `:235`) changent de constructeur (`setUp`, `:41-57`) : un `createStub(IndustryUserSettingsRepository::class)`, dont `findOneBy()` rend `null`, suffit à les garder verts, puisqu'aucun ne vise les frais.
  - Tests à ajouter : F1 à F8, en observant le `CreateProjectData` reçu par `GroupIndustryProjectService::createProject()` (aujourd'hui un stub, `:44`, à remplacer par un mock avec capture dans ces tests). Modèle pour fournir ou non des réglages : `CreateProjectProcessorTest::settingsRepositoryReturning()` (définie `:399`, utilisée `:145` avec réglages et `:280` sans).
  - Test de R8 : `assertSame(3.6, (new CreateProjectData('x', []))->brokerFeePercent)` et idem pour `salesTaxPercent`, plus la même assertion sur `GroupIndustryProject` et `GroupIndustryProjectResource` neufs. Aucune garde automatique contre le littéral `3.6` n'existe (`tests/Unit/Architecture/` ne contient que `AdminByCharacterIdTest.php`) : le test de défauts ci-dessus est la garde.
  - `GroupIndustryProjectServiceTest` et `GroupIndustryBomGoldenCharacterizationTest` construisent `CreateProjectData` sans frais : ils restent verts tant que `CreateProjectData` garde un défaut égal à 3,6 (R8).
- Front (Q4) : `MemberTable.vue` n'a pas de test. On en crée un pour la visibilité des boutons selon que l'utilisateur est propriétaire ou admin et selon le rôle de la ligne.

## Glossaire

Termes proposés pour [`docs/glossary.md`](../glossary.md). Ils ne sont pas encore ajoutés : le glossaire décrirait un comportement qui n'existe pas encore. À ajouter dans le commit d'implémentation (l'entrée « Frais de courtage » / « Taxe de vente » est validée par la décision Q2 du 2026-10-29) :

- **Admin** (Project admin), section « Industrie collaborative » : membre `accepted` au rôle `admin`, promu par le propriétaire seul. Il accepte les demandes, retire les membres de rôle `member`, revoit les contributions, enregistre les ventes et modifie le projet. Il n'agit pas sur un autre admin (#170). Code : `GroupMemberRole::Admin`, `GroupProjectAccessChecker::assertAdminOrOwner()`.
- **Frais de courtage** (Broker fee) et **Taxe de vente** (Sales tax), section « Marché » : pourcentages prélevés sur une vente au marché. Ils sont réglés par l'utilisateur dans Industry en fraction (`0.036` = 3,6 %, défaut 3,6 %), et portés par un projet collaboratif en pourcents, copiés à la création depuis les réglages du propriétaire (#170). Code : `IndustryUserSettings::$brokerFeeRate`, `$salesTaxRate`, `EveConstants::DEFAULT_BROKER_FEE_RATE`, `DEFAULT_SALES_TAX_RATE`, `GroupIndustryProject::$brokerFeePercent`, `$salesTaxPercent`.

## Décisions de Sylvain (2026-10-29)

- **Q2 — Frais par défaut à la création : option (b).** Un frais absent prend la valeur des réglages Industry de l'utilisateur qui crée le projet (`getBrokerFeeRate()`, `getSalesTaxRate()`, en pourcents), avec 3,6 % (`EveConstants::DEFAULT_BROKER_FEE_RATE`, `DEFAULT_SALES_TAX_RATE`) quand il n'a aucun réglage. Modèle : `CreateProjectProcessor::sellTaxRate()`. Le front (`NewProjectModal.vue`) ne change pas. Les projets créés par un joueur aux frais réglés n'affichent plus 3,6 %.

## 🚦 Questions pour Sylvain

Q1, Q3 et Q4 : recommandations retenues pour la boucle de nuit, à relire par Sylvain.

1. **Q1 — Un admin qui tente de se rétrograder lui-même reçoit-il 403** (R-E4), comme pour tout autre admin ? Recommandation : **oui**. La règle reste unique, l'interface ne le propose pas, et il peut toujours quitter le projet ou demander au propriétaire.
2. **Q2** : tranchée, voir « Décisions de Sylvain ».
3. **Q3 — Un membre sans personnage principal est-il exposé avec `characterId: null` au lieu de `0` ?** Recommandation : **oui**. Le type est déjà nullable de bout en bout, `null` est déjà émis pour un compte supprimé, et le front le gère (portrait de repli). La valeur change dans le contrat de l'API.
4. **Q4 — `MemberTable.vue` est-il corrigé dans ce lot** (boutons Promote, Demote et Kick masqués selon R5) ? Recommandation : **oui**. Sans ce lot, après R1, un admin verrait des boutons qui échouent en 403.
