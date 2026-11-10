# Spec #531 — Module Minage : 5 pages, barre d'onglets de module, pont chunk ↔ opération

Lot 1 `group:mining-module`, `circuit:l`. Frontend surtout ; un champ de lecture ajouté côté chunk et côté opération (aucune migration : la clé étrangère `MiningOperation::$chunk` existe). Cible validée : `.claude/tasks/ux-architecture-2026-11-09.md` §4-5 (A1 à A4, D4), maquette `docs/design/mockups/navigation.html`. Rien n'est retiré : tout est déplacé.

## Preuves de l'issue vérifiées (journal)
- Vrai : sous-vues de `MiningTab` hors URL, lien `MiningStockpileCoverage.vue:11` vers `ledger?tab=mining`, `MiningOperationNotifier.php:24`, `MoonChunkAlertSender.php:23` (`/ledger?tab=mining&view=moonChunks`).
- Périmé : `MiningTab` compte **4** sous-vues (`entries|oreValue|moonChunks|moonTax`, `MiningTab.vue:30`), la taxe lunaire est livrée (#369, `MoonTaxTab`) ; le bouton « Créer l'opération » depuis un chunk existe (#274, `?chunk=<id>` sur la liste). Reste à faire : afficher l'opération sur le chunk, le chunk sur l'opération, et le lien site → opération (aucun `RouterLink` site → opération, `MiningSiteDetail.vue:171` ne renvoie qu'à la liste).

## Règles

**R1 — Routes.** Les noms de route existants sont conservés ; seuls les chemins changent.

| Page | Chemin | Nom | Sous-vues `?tab=` |
|---|---|---|---|
| Opérations | `/mining/operations` | `mining-operations` | `mine` (défaut, récurrences incluses), `available`, `archive` |
| Détail / invitation | `/mining/operations/:id`, `/mining/operations/join/:code` | `mining-operation-detail`, `mining-operation-join` | — |
| Lunes | `/mining/moons` | `mining-moons` | `calendar` (défaut), `tax` ; `?chunk=<id>` ouvre la création prérémplie |
| Sites | `/mining/sites` ; détail `/mining/sites/:id` | `mining-sites`, `mining-site-detail` | — |
| Valeur du minerai | `/mining/ore-value` | `mining-ore-value` | — |
| Mon minage | `/mining/my-mining` | `mining-my-mining` | `ledger` (défaut), `season` |

`/mining` redirige vers `/mining/operations`. Les vues liste / semaine / mois de Lunes restent un état interne de `MoonChunksTab`.

**R2 — Anciennes URL (redirections, params et query conservés).**

| Ancienne | Cible |
|---|---|
| `/mining-operations` (`tab` absent, `mine`, `available`, `archive`) | `/mining/operations` avec le même `tab` |
| `/mining-operations?tab=season` | `/mining/my-mining?tab=season` |
| `/mining-operations?tab=sites` | `/mining/sites` |
| `/mining-operations?chunk=<id>` | `/mining/moons?chunk=<id>` |
| `/mining-operations/:id`, `/mining-operations/join/:code`, `/mining-sites/:id` | `/mining/operations/:id`, `/mining/operations/join/:code`, `/mining/sites/:id` |
| `/ledger?tab=mining` sans `view` ou `view=entries` | `/mining/my-mining` |
| `/ledger?tab=mining&view=oreValue` | `/mining/ore-value` |
| `/ledger?tab=mining&view=moonChunks` | `/mining/moons` |
| `/ledger?tab=mining&view=moonTax` | `/mining/moons?tab=tax` |

La redirection de `/ledger?tab=mining` se fait dans la route (`beforeEnter`), pas dans `Ledger.vue`. Revenus perd l'onglet Minage (`Ledger.vue`, `MiningTab.vue`) ; sa vue d'ensemble (ligne Minage) et les réglages de valorisation (localStorage `miningSelectedStructure`, `miningExportTax`, `miningReprocessYield`) ne bougent pas (lots #533, #532). Les clés localStorage restent lues par la page Valeur du minerai.

**R3 — URL émises par le backend.** `MiningOperationNotifier` émet `/mining/operations/<id>`, `MoonChunkAlertSender` émet `/mining/moons`, le générateur de lien d'invitation (`miningOperationJoinUrl.ts`) émet `/mining/operations/join/<code>`. Les notifications déjà stockées gardent l'ancienne route, que R2 redirige.

**R4 — Barre d'onglets de module (composant partagé).** `ModuleTabBar.vue` dans `frontend/src/components/layout/` : reçoit une liste `{ id, label, to, count? }`, rend des liens de route (une URL par onglet, `aria-current` sur l'actif, `role="navigation"`), style HUD de la maquette, libellés longs tronqués avec infobulle. Autre composant des sous-vues `?tab=` : `useTabQuery` existant, inchangé. Le composant ne connaît pas le minage : les lots #533 à #536 le réutilisent. Il est monté en tête de chaque page Minage (5 onglets : Opérations, Lunes, Sites, Valeur du minerai, Mon minage), y compris sur le détail d'opération et de site (onglet Opérations / Sites actif).

**R5 — Dernière page visitée.** Composable `useModuleLastPage` : clé localStorage unique `evetools_module_last_page`, objet `{ <moduleId>: <chemin> }`, écrite à chaque navigation dans le module (chemin sans id de détail : un détail mémorise sa page liste). L'entrée de sidebar Minage (entrée à plat `mining`, sans groupe, labellée « Minage », active sur le préfixe `/mining/`) ouvre ce chemin. Valeur illisible, absente ou chemin inconnu : `/mining/operations`. Accès localStorage dans try/catch.

**R6 — Sidebar et palette.** Une seule entrée Minage dans la sidebar ; la palette (`useNavigationEntries.ts`) propose les 5 pages et leurs sous-vues (`available`, `archive`, `my-mining?tab=season`, `moons?tab=tax` ; pas de `?tab=mine`, doublon de la page) ; l'entrée `ledger?tab=mining` est retirée. Modules masqués : l'id masquable `mining-operations` des utilisateurs existants reste reconnu (constante d'id hérité, comme `group-industry`) et masque l'entrée Minage, la palette et les 5 pages.

**R7 — Mon minage.** Statistiques + lignes du ledger perso (onglet `ledger`) et Saison (`season`). La page porte son sélecteur de période (mêmes presets que Revenus) qui lit et écrit la clé existante `ledgerSelectedDays` : la période déjà choisie est conservée. La valeur inconnue n'est jamais affichée comme 0 (comportement actuel inchangé).

**R8 — Valeur du minerai.** `OreValueTab` + `ReprocessingProfilePanel` + `OreSalePanel`, avec la sélection de structure et la taxe d'export qui vivaient dans `MiningTab` ; Mon minage lit aussi ces clés (`miningSelectedStructure`, `miningExportTax`) pour valoriser ses lignes, sans les réécrire. `MiningTab` peut rester le contenu de l'onglet `ledger` de Mon minage, amputé de ses autres sous-vues. Le lien « rendement inconnu » (`MiningStockpileCoverage.vue:11`) pointe vers `mining-ore-value`.

**R9 — Chunk → opération.** `GET /mining/corporation-extractions` ajoute à chaque chunk `linkedOperation` : `{ id, label, status }` de l'opération **la plus récente** (par `plannedStartAt`) liée au chunk dont l'utilisateur est propriétaire ou membre (`pending` ou `accepted`, archivées comprises ; sans la clause « visible par la corporation » de `findArchivedReadableBy`), et `linkedOperationCount` (nombre de ces opérations), ou `null` / 0. Dans Lunes (liste, semaine, mois), une ligne avec `linkedOperation` affiche « Opération liée → » (vers `mining-operation-detail`) et « +N » si `linkedOperationCount > 1`, **à la place** de « Planifier l'opération ». Sans opération, le bouton ouvre `NewMiningOperationModal` prérempli (nom de la lune, heure d'arrivée, raffinerie via le chunk, comme aujourd'hui) ; après création la page ouvre l'opération. Le libellé du bouton devient « Planifier l'opération ».

**R10 — Opération → chunk.** `MiningOperationResource` ajoute `chunk` : `{ id, moonId, moonName, structureId, structureName, chunkArrivalTime }` quand `chunkId` est non nul et que le lecteur est de la corporation du chunk (voir Accès), sinon `null`. Le détail d'opération montre un bloc « Chunk lié » (lune, raffinerie, arrivée en EVE time, lien `mining-moons`) ; les cartes de la liste (`mine`, `available`) et le tableau des archives montrent « Lune liée → ». Nom absent : l'identifiant, jamais une valeur inventée.

**R11 — Site → opération.** `MiningSiteDetail` affiche « Opération → » (vers `mining-operation-detail`) quand `operationId` est non nul ; le lien « retour » renvoie à `mining-sites`. La liste des sites (`MiningSiteHistory` en page) a une colonne Opération avec le même lien.

## Exemples (tests rouges)

| # | Entrée | Sortie |
|---|---|---|
| E1 | `/mining-operations?tab=sites` | `/mining/sites` |
| E2 | `/mining-operations?tab=season` | `/mining/my-mining?tab=season` |
| E3 | `/mining-operations?chunk=abc` | `/mining/moons?chunk=abc` |
| E4 | `/ledger?tab=mining&view=moonTax` | `/mining/moons?tab=tax` |
| E5 | `/ledger?tab=mining` | `/mining/my-mining` |
| E6 | `/mining-operations/<uuid>` | `/mining/operations/<uuid>` |
| E7 | dernière page `/mining/moons`, clic Minage | `/mining/moons` |
| E8 | localStorage illisible, clic Minage | `/mining/operations` |
| E9 | chunk `extracting`, aucune opération de l'utilisateur | bouton « Planifier l'opération », modale prérempliée |
| E10 | chunk avec 2 opérations de l'utilisateur (la plus récente `planned`) | « Opération liée → » + « +1 », pas de bouton |
| E11 | chunk dont l'unique opération appartient à un collègue | bouton « Planifier l'opération » (rien ne fuit) |
| E12 | opération avec `chunkId` d'une lune sans nom | bloc « Chunk lié » avec l'identifiant de la lune |
| E13 | site avec `operationId` | lien « Opération → » ; sans `operationId`, aucun lien |

## Cas limites
- Chunk `exploded` : pas de bouton (règle #274), mais « Opération liée → » reste affiché s'il y en a une.
- Chunk supprimé par resynchronisation : `chunkId` nul (`SET NULL`), pas de bloc « Chunk lié ».
- `?chunk=<id>` inconnu ou explosé sur Lunes : page chargée sans modale, pas d'erreur bloquante.
- Onglet inconnu (`?tab=zz`) : onglet par défaut (comportement de `useTabQuery`).
- Onglet Taxe lunaire : même visibilité qu'aujourd'hui (`MoonTaxTab`), seul son emplacement change.
- Mise en page : noms de lune ou de raffinerie longs tronqués avec infobulle, 5 onglets sans débordement horizontal à 360 px.

## Accès et fraîcheur
- `linkedOperation` ne contient que des opérations dont l'utilisateur est propriétaire ou participant (mêmes critères que `findForParticipant` et `findArchivedReadableBy`) ; jamais celles d'un autre membre.
- `chunk` de `MiningOperationResource` n'est rempli que si `chunk.corporationId` égale la corporation connue du lecteur (`User::getCorporationId()`, rafraîchie seulement à la connexion : un ex-membre ou un participant d'une autre corporation ne voit pas le bloc). Défaut préexistant repris tel quel.
- Lien site → opération : `operationId` est déjà exposé par `MiningSiteResource` ; la page de l'opération contrôle l'accès comme aujourd'hui (un site partagé dont l'opération n'est pas accessible mène à un refus, préexistant).
- Aucun nouvel appel ESI, aucun nouveau scope, aucune URL d'API `/api/...` modifiée.

## Tests existants contredits ou modifiés
`useNavigationEntries.spec.ts`, `NavigationEntryTabsContract.spec.ts`, `MainLayoutNavigation.spec.ts`, `CommandPalette.spec.ts`, `commandPaletteSearch.spec.ts` (chemins et entrées palette) ; `miningSiteRoute.spec.ts`, `MiningCompanionHostFree.spec.ts` (nom de route + query `chunk`) ; `MoonChunkAlertSenderTest.php`, `MiningOperationNotificationsApiTest.php` (routes émises) ; `miningTabMoonChunks.spec.ts` (sous-vues déplacées hors de `MiningTab`) ; `MiningOperationList.spec.ts` (onglets `season` et `sites` sortis) ; `miningTabMoonTax.spec.ts`, `MainLayoutResponsive.spec.ts`, `miningOperationJoinUrl.spec.ts`, `industryJoinRoute.spec.ts`, `miningStockpileCoverage.spec.ts`, `MiningOperationListCompanion.spec.ts`, `LedgerIndustryTab.spec.ts`, `RecordsMiningOperationNotifications.php`, `CorporationMiningExtractionsProviderTest.php` (harnais) ; détail dans `.claude/tasks/531-wiring.md`. Modifications d'assertions à justifier un par un dans la PR (technicité 3).

## Hors périmètre
Profil de valorisation serveur et base de prix unique (#532) ; vue d'ensemble de Revenus (#533) ; valeur du chunk dans le calendrier (#368) ; contenu de la taxe lunaire (#369, livré) ; sidebar finale à 8 modules, groupes repliables, Paramètres (lots ui-shell) ; colonne Site dans la liste des opérations ; onglet « Récurrences » séparé (elles restent sous `mine`) ; refonte du détail d'opération.

## Glossaire
Ajoutés : **Barre d'onglets de module** (`ModuleTabBar`), **Dernière page visitée** (`useModuleLastPage`), **Chunk lié** (`linkedOperation`, `chunk`).
