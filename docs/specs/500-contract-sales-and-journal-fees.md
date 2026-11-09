# #500 — Contrat ESI rattaché à une vente, frais réels du contrat, item choisi parmi ceux du projet

Suite de #325 (canal de vente) et reprise de la question de #324 (journal wallet). Décision du 2026-11-09 : la sync du journal est livrée ici et sert aux deux. Maquette : `industry-project-v3.html` (ventes, résumé financier). Livré en deux PR : lot 1 (règles 1 à 8, backend et front du rattachement et des frais), lot 2 (règles 9 et 10, front).

## Règles du lot 1

1. **Journal wallet des personnages** : `WalletJournalSyncService::syncCharacterJournal` lit `GET /characters/{id}/wallet/journal/` (`getPaginated`, scope `esi-wallet.read_character_wallet.v1`, sinon rien) et ne garde que les lignes de `ref_type` `contract_brokers_fee` et `contract_sales_tax` (`CachedWalletJournalEntry::CONTRACT_FEE_REF_TYPES`) : `journalEntryId`, `refType`, `amount` (négatif en jeu), `date`, `contextId`, `contextIdType`, personnage. Une ligne déjà connue (personnage + `journalEntryId`) est ignorée. Appelé par `SyncCharacterWalletTransactionsHandler` après les transactions (même cadence horaire, aucun nouveau message). Mercure `wallet-journal` (started, progress, completed ; `syncError` si l'ESI ou le flush échoue, l'erreur est journalisée et ne casse pas la sync des transactions) ; topic déclaré dans `getTopicsForUser()`, type silencieux du fil de notifications.
2. **Frais d'un contrat** : `CachedWalletJournalEntryRepository::contractFeesByContractId` (via `ContractSaleFees`) = somme des `-amount` des lignes du journal dont `contextId` = contrat et `contextIdType` = `contract_id`. Aucune ligne : inconnu (`null`), jamais 0 (une vente par contrat paie toujours une taxe de vente et un courtage).
3. **Vente par contrat** : `IndustryProjectSale::$contractId` (bigint nullable, unique), migration. Une vente de canal `contract` rattachée à un contrat expose `contractId`, `contractFees` (`null` si inconnus) dans `ProjectSaleResource`. Les ventes sans contrat gardent `contractId` nul.
4. **Candidats** : `GET /api/industry/projects/{projectId}/contract-suggestions` (`assertAdminOrOwner`) lit, pour chaque personnage du lecteur avec jeton, `/characters/{id}/contracts/` : contrats `item_exchange`, `finished`, émis par le personnage, `date_completed` postérieure à la création du projet, au plus `MAX_CANDIDATES` (20) les plus récents non déjà rattachés ; puis `/characters/{id}/contracts/{contractId}/items/`. Un contrat est proposé seulement si ses items inclus (`is_included`) sont d'un seul type, item du projet (`IndustryProject::getItemTypeName`). Champs : `contractId`, `typeId`, `typeName`, `quantity` (somme), `price`, `unitPrice` (prix / quantité), `completedAt`, `sellerCharacterId`, `sellerCharacterName`, `contractFees` (`null` si inconnus). Un personnage illisible (pas de jeton, `EveAuthRequiredException`, `EsiApiException`) est listé dans `unreadableCharacters` avec sa raison (`no_token`, `esi_error`), les autres sont lus.
5. **Rattachement** : `POST /api/industry/projects/{projectId}/sales/from-contract` avec `{contractId, characterId}` (`assertAdminOrOwner`, projet non terminé) relit le contrat chez l'ESI comme la règle 4 (404 si absent des candidats, 409 s'il est déjà rattaché à une vente de n'importe quel projet), crée la vente (type, quantité, prix unitaire, `soldAt` = `date_completed`, `seller` = personnage, `recordedBy` = lecteur, canal `contract`, `contractId`) et publie `sale_recorded`. Réponse = la vente.
6. **Distribution** : `DistributionResult::$contractFees` (somme des frais de chaque vente par contrat ; `null` dès qu'une vente par contrat a des frais inconnus). Le revenu net = revenu − courtage − taxe − frais des contrats ; il devient inconnu (`null`, donc marge et payouts inconnus) quand les frais d'un contrat sont inconnus. `ProjectDistributionResource::$contractFees` l'expose. Les ventes de canal `contract` sans `contractId` (saisie à la main, #325) n'ont aucun frais connu : `contractFees` ne les compte pas et ne devient pas `null` pour elles (le propriétaire a saisi un prix net, comme avant).
7. **Virements (#324)** : le revenu détenu d'une vente par contrat rattachée = prix − ses frais ; frais inconnus : raison `unknown_fees`. Une vente de canal `contract` sans `contractId` garde le prix entier.
8. **Front** : « Rattacher un contrat » dans l'onglet Ventes (propriétaire et admin) : liste des candidats avec frais (« inconnus » sinon), bouton Rattacher, personnages illisibles signalés ; ligne « Frais des contrats » dans `FinancialSummary` (inconnue : tiret, jamais 0, et sans cascade graphique comme les autres frais inconnus) ; revenu séparé marché / contrats dans le résumé (`marketRevenue`, `contractRevenue` de la distribution) ; recap Discord : ligne frais des contrats quand connus.

## Règles du lot 2

9. **Item d'une vente** : le formulaire d'ajout d'une vente choisit l'item parmi les items du projet (`typeId` et nom) avec la quantité restante à vendre par item ; saisie libre retirée du formulaire de création (le backend accepte toujours `typeId` 0 pour les ventes existantes).
10. **Ligne « vendus / produits »** sous le tableau des ventes, par item : quantité vendue / quantité produite du projet.

## Exemples chiffrés

Contrat vendu 600 000 000 ISK, journal : `contract_brokers_fee` −3 000 000 et `contract_sales_tax` −21 600 000 → frais 24 600 000, détenu 575 400 000. Même contrat sans ligne de journal : frais inconnus, revenu net, marge, payouts et virements inconnus.

## Hors périmètre

- Contrat de plusieurs types d'items ou contrat de vente d'un item hors projet (non proposé) ; contrats `auction` ; contrats corporation.
- Journal de corporation (voir `CorporationWalletJournalSync`) ; vérification automatique des virements de #324 par ce journal (reste à faire sur ces lignes : un virement de joueur à joueur n'est pas un `ref_type` conservé ici).

## Décisions ouvertes

- Frais réels d'un contrat item exchange en jeu : le journal garde `contract_brokers_fee` et `contract_sales_tax` (à confirmer en jeu).
