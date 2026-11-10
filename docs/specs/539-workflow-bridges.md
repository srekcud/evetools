# Spec #539 — Ponts entre étapes : Marges, BOM, Stockpile, job, produit PI

`group:workflow-bridges`, `circuit:m`. Cible : `.claude/tasks/ux-architecture-2026-11-09.md` §4-5 (D3), maquette `docs/design/mockups/navigation.html` (pages `margins`, `project`, `production`, `planetary`). Les ponts sont des boutons ou liens dans les pages des modules ; ni sidebar, ni layout, ni page Paramètres (#536). Rien de visible n'est retiré. Backend : un seul ajout, `project` dans la réponse de `GET /api/industry/slots`, sans migration.

## Preuves de l'issue vérifiées sur origin/main
- Vrai : `ProfitMarginTab.vue` n'a aucune action de sortie ; Achat vs Prod crée déjà un projet via `navigationIntent = { target: 'projects', prefill }` (`BuyVsBuildTab.vue::onCreateOptimalProject`), consommé par `GroupIndustryList.vue::consumeNavigationIntent` (`NewProjectModal` prérempli).
- Vrai : `BomTable.vue` (matières à approvisionner, `projectStore.materials`) n'a aucun export ; `MaterialSourcing.vue::multibuyOf` et `StockpileShoppingList.vue::copyToClipboard` produisent chacun leur texte `nom<TAB>quantité`.
- Vrai : `Appraisal.vue` n'a aucune entrée externe (`inputText` local, `analyze()`).
- Vrai : `SlotTrackerService::getSlotStatus` n'expose aucun lien projet ; `IndustryProjectJobMatch` (`esiJobId` → `bomLine` → `project`) existe.
- Vrai : `PiProductionTable.vue` affiche les produits sans lien ; la route marché détail est `/market?tab=detail&typeId=N`.
- Interprétation : « hors périmètre PI » du brief vise les seuils et alertes PI (#537), pas le lien produit → marché demandé par cette issue (lien front seul, aucune donnée PI modifiée).

## Règles

**R1 — Marges : actions de sortie.** Dans `ProfitMarginTab.vue`, quand un résultat est affiché (`result` non nul), une barre d'actions (composant `components/industry/MarginBridgeActions.vue`, props `result: ProfitMarginResult`, `meLevel: number`) montre trois contrôles, avant les cartes de synthèse :
1. « Créer le projet » : `industryStore.navigationIntent = { target: 'projects', prefill: { typeId, typeName: typeNameLabel(result.typeName, result.typeId), runs: result.runs, me: meLevel, isT2: result.isT2 } }`. Le ME est celui analysé (champ ME courant de l'onglet) ; `te` n'est pas fourni (même défaut que Achat vs Prod).
2. « Achat vs Prod » : `navigationIntent = { target: 'buy-vs-build', typeId }`.
3. « Voir au marché » : `RouterLink` vers `/market?tab=detail&typeId=<typeId>`.
Les contrôles restent visibles pendant un rechargement (`loading`) ; si `result` est nul (aucune analyse) la barre n'existe pas.

**R2 — Texte multibuy partagé.** `utils/multibuy.ts` exporte `multibuyText(lines: readonly { typeName: string; quantity: number }[]): string` : une ligne `nom<TAB>quantité` par entrée, séparées par `\n`, sans ligne finale ni en-tête ; liste vide = chaîne vide. `MaterialSourcing.vue::multibuyOf` et `StockpileShoppingList.vue::copyToClipboard` l'utilisent (comportement et texte copiés inchangés).

**R3 — Passage vers l'Évaluateur.** `stores/appraisalHandoff.ts` (Pinia) : état `pendingText: string | null`, actions `send(text: string)` (ignore une chaîne vide ou blanche) et `take(): string | null` (renvoie le texte et remet l'état à `null`). `Appraisal.vue`, au montage, appelle `take()` : si un texte existe, il remplit `inputText` puis lance `analyze()` (un seul appel, la structure et le transport par défaut s'appliquent). Sans texte en attente, le comportement actuel est inchangé (aucune analyse au montage).

**R4 — BOM d'un projet.** Dans l'en-tête de la section « Matières à approvisionner » de `BomTable.vue`, deux boutons via `components/appraisal/MultibuyBridgeActions.vue` (props `lines: { typeName: string; quantity: number }[]`) :
- « Copier multibuy » : copie `multibuyText(lines)` dans le presse-papiers, libellé « Copié » 2 s ;
- « Évaluer dans l'Évaluateur » : `appraisalHandoff.send(multibuyText(lines))` puis `router.push({ name: 'appraisal' })`.
`lines` = `projectStore.materials` en `{ typeName, quantity: requiredQuantity }` (mêmes lignes et quantités que la colonne « Requis », y compris les lignes déjà couvertes). Boutons désactivés quand la liste est vide.

**R5 — Stockpile.** `StockpileShoppingList.vue` garde son bouton « Copier » existant (texte inchangé) et ajoute « Évaluer dans l'Évaluateur » (même `MultibuyBridgeActions`, bouton d'évaluation seul via la prop `showCopy: false`). Les lignes sont celles que le copier exporte : lignes filtrées (`criticalOnly` respecté) dont `deficit > 0`, quantité = `deficit`.

**R6 — Slots : job vers projet.**
- Backend : chaque entrée de `characters[].jobs[]` et de `timeline[]` porte `project: { id: string, name: string | null } | null`. `id` est l'UUID (RFC 4122) du projet, `name` son nom (nul si le projet n'est pas nommé). `project` est non nul seulement si un `IndustryProjectJobMatch` a le `esiJobId` du job **et** que le projet est visible de l'utilisateur appelant : propriétaire, ou participant au statut `Accepted`. Un match vers le projet d'un tiers non accessible donne `project: null` (aucune fuite d'id ni de nom). Un job apparié à plusieurs projets visibles : le match le plus ancien (`matchedAt`).
- Implémentation : `IndustryProjectJobMatchRepository::findVisibleProjectsByEsiJobIds(User, int[]): array<int, IndustryProject>` (une requête pour tous les jobs), injecté dans `SlotTrackerService` ; types `SlotTrackerJob` et `TimelineJob` du front étendus (`project`).
- Front (`IndustrySlotsSection.vue`) : la ligne de job du tableau et l'info-bulle de la timeline affichent un lien vers `{ name: 'industry-project', params: { id } }` quand `project` est non nul ; libellé = `project.name`, ou `industry.slots.projectLinkFallback` (« Projet ») si le nom est nul ; nom long tronqué avec infobulle. Pas de lien si `project` est nul.

**R7 — Planétaire : produit vers marché.** Dans `PiProductionTable.vue`, chaque produit (ligne `item`) devient un `RouterLink` vers `/market?tab=detail&typeId=<item.typeId>` (le nom reste affiché avec `typeNameLabel`, `OpenInGameButton` conservé). Le lien reste affiché même si le module marché est masqué dans la sidebar (la page reste joignable).

**R8 — i18n.** Clés en et fr pour les libellés ci-dessus sous `industry.bridges.*` (marges, multibuy, Évaluateur) et `industry.slots.projectLinkFallback` ; en français, « l'Évaluateur » (R7 de #535). Pas de clé retirée.

## Cas limites
- Marge d'un produit T2 : `me` fourni est celui affiché, `isT2` vrai ; le modal Projet applique son propre défaut TE.
- `take()` après un rechargement de page : état perdu, l'Évaluateur s'ouvre vide (pas de persistance).
- Un texte envoyé deux fois de suite : le second `send` remplace le premier.
- Job sans match, ou match sur un projet inaccessible : aucun lien, pas d'erreur.
- Utilisateur propriétaire d'un projet sans ligne participant : le projet est visible (propriétaire).
- Nom de type inconnu côté BOM : la ligne exportée garde `typeName` tel que reçu (le nom vide est exclu par l'API actuelle, pas d'invention de nom).

## Hors périmètre
Sidebar, dernière page du module, page Paramètres, seuils et alertes PI (#537, #536). Format d'export EFT/D-Scan. Lien depuis les cartes du Pont d'accueil. Redirection d'anciennes URL : aucune URL ne change.

## Décisions
- BOM : quantité = `requiredQuantity` (même lecture que la table) ; Stockpile : déficit (cohérent avec son copier).
- « Voir au marché » ouvre le détail du produit, pas un filtre sur la liste.
- Aucun nouveau bouton « Achat vs Prod » avec runs/ME : l'onglet ne les accepte pas dans l'URL (issue : « même produit »).
- Pas de persistance du texte envoyé à l'Évaluateur (mémoire de session uniquement).
