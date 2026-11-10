# #532 · Profil de valorisation minière serveur et base de prix unique

Mode nuit : spec validée en autonomie (décision de Sylvain du 2026-11-10). Décisions prises sont marquées **[D]**.

## Contexte et preuves vérifiées sur origin/main (f424744e)
- Le prix unitaire du minerai sort de `OreValueService::getBatchOrePrices(typeIds, structureId, yield, exportTax, side)` ; chaque appelant choisit ses propres paramètres : table (`OreValueTableService`, paramètres de requête), ledger (`MiningEntryCollectionProvider`, `structureId`/`exportTax` de requête lus en localStorage par le front), stats et dashboard (`MiningBestValueCalculator` : maximum de 4 sources, côté Sell, Jita, sans taxe), opération (`MiningOperationUnitPrices` : réglages propres), sites (`MiningSiteScanRecorder`) et chunks (`MoonChunkValueReader`) via `MiningSiteUnitPrices` (Jita Buy compressé, sans taxe).
- Preuves périmées, sorties de la spec : la constante 0,78 n'existe plus (#269, le rendement vient du profil de retraitement) ; `MiningFleetStorageVerifier` ne lit que `compressedTypeId`, aucun prix ; `miningReprocessYield` n'est lu nulle part dans `src` (le rendement est déjà serveur, `ReprocessingProfile::getReprocessYield()`).
- Réutilisés tels quels : `MiningValuationMarket` (jita, structure), `MiningValuationForm` (raw, compressed, reprocessed, `priceKeyOn`), `MarketSide`, le profil de retraitement (rendement).

## Règles
1. **Profil de valorisation minière** : une ligne par utilisateur (`MiningValuationProfile`, table `user_mining_valuation_profiles`, migration), champs : marché, côté, forme, structure (id et nom), taxe d'export (ISK/m³, ≥ 0). Défauts sans ligne : Jita, Buy, compressé, aucune structure, taxe 0 **[D : Buy = défaut de l'opération et du site ; taxe 0 car le 1200 du front n'était qu'une valeur de saisie]**. Le rendement n'y est pas stocké : il vient du profil de retraitement de l'utilisateur, inconnu s'il n'est pas configuré.
2. **API** (API Platform) : `GET` et `PATCH` (merge-patch) `/api/mining/valuation-profile`, resource sans identifiant, `GET` renvoie les défauts avec `persisted: false` quand la ligne manque, `PATCH` crée ou met à jour. Validation : marché structure exige une structure (422), taxe négative (422), côté ou forme inconnus (422). Passer au marché Jita conserve la structure mémorisée.
3. **Lecteur unique** `MiningProfileUnitPrices::read(User, typeIds)` : appelle `getBatchOrePrices` avec les paramètres du profil (structure dès que le profil en a une, pour garder les colonnes de prix de structure du ledger, même sous marché Jita ; rendement du profil de retraitement ou null ; taxe ; côté) et retient la clé `MiningValuationForm::priceKeyOn(marché)`. Retourne prix unitaire par type (null = inconnu, jamais 0), `valuationSource` (marché, côté, forme, nom de structure) et l'heure de synchronisation. Forme retraité sans rendement : tous les prix inconnus. Marché structure sans prix pour un type : inconnu, pas de repli sur Jita.
4. **Appelants alignés sur le lecteur** :
   - table ISK/m³ : marché, côté, structure, rendement et taxe d'export viennent du profil quand la requête ne les donne pas (les paramètres explicites restent acceptés) ; la table applique la taxe (aujourd'hui 0 en dur) et garde ses trois formes par ligne ;
   - ledger (`MiningEntryCollectionProvider`) : `structureId` et `exportTax` de requête retirés au profit du profil ; chaque ligne porte `unitValue` (prix unitaire du profil, null si inconnu) et `valuationSource` ; les champs de prix par forme existants restent ;
   - stats, stats par type, journalières et dashboard (`MiningBestValueCalculator`) : la valeur d'une unité est celle du lecteur (fin du maximum de 4 sources) ; type sans prix : total inconnu avec `missingPriceTypeIds`, comme aujourd'hui **[D : le nom de la classe est conservé, renommage hors lot]** ;
   - sites (`MiningSiteScanRecorder`, utilisateur auteur du scan) et chunks (`MoonChunkValueReader`, utilisateur courant) : lecteur du profil ; une valeur de scan déjà enregistrée n'est pas recalculée, et chaque nouveau scan mémorise sa source (colonne JSON nullable `valuation_source` de `mining_site_scans`, même migration, null pour les anciens scans : affichés « source inconnue ») ;
   - opération : inchangée, ses réglages surchargent le profil et ses prix restent figés ;
   - **taxe lunaire** (`MoonTaxAssessor`) : inchangée, base fixe Jita Buy compressé sans taxe, extraite en `MoonTaxUnitPrices` **[D : un relevé de taxe de corporation ne dépend pas du profil personnel de celui qui l'ouvre ou le clôture]** ;
   - fleet storage : inchangé (pas de valorisation).
5. **Source affichée** : table, ledger (lignes, stats et stats par type), site (source du scan) et chunk affichent un libellé « marché · côté · forme » (composant partagé, noms de structure tronqués avec infobulle). Valeur inconnue : tiret, jamais 0.
6. **Front** : `useMiningValuationSettings` lit et écrit le profil serveur (store Pinia) et n'utilise plus localStorage ; `useOreSale` et `MiningTab` lisent le même profil. Réglage depuis Valeur du minerai : les quatre boutons marché/côté et un sélecteur de forme écrivent le profil (le tableau montre toujours les trois formes), plus structure et taxe. La section Minage de Paramètres arrive avec le lot Paramètres.
7. **Reprise locale** : à la première ouverture, si `persisted` est faux et que `miningSelectedStructure` ou `miningExportTax` existe, le front envoie un PATCH (structure, taxe ; marché Jita) puis supprime les clés ; en cas d'échec les clés restent et l'import est retenté. Une valeur locale non lisible est ignorée sans erreur bloquante mais journalisée en console.
8. **Anciennes URL** : aucune route ne change ; les paramètres de requête retirés sont ignorés.

## Exemples chiffrés (futurs tests rouges)
Minerai A : volume brut 0,1 m³, volume compressé 0,1 m³, tailles de portion égales. Prix : Jita Buy compressé 500, Jita Sell compressé 560, Jita Buy brut 450, valeur retraitée Jita Buy 520 (rendement 0,8).

| Profil | Valeur unitaire | Table (ISK/m³ de la forme) |
|---|---|---|
| défaut (Jita, Buy, compressé, taxe 0) | 500 | compressé 5 000 |
| Jita, Buy, compressé, taxe 1 000 | 400 (500 − 1 000 × 0,1) | compressé 4 000 |
| Jita, Sell, compressé | 560 | 5 600 |
| Jita, Buy, retraité, rendement 0,8 | 520 | 5 200 |
| Jita, Buy, retraité, rendement inconnu | inconnu | retraité null |
| structure 60003760, Buy, compressé, aucun prix structure pour A | inconnu | null (pas de repli Jita) |

Ledger, stats, site et chunk d'un même minerai sous un même profil donnent la même valeur unitaire (premier critère de l'issue). Avant/après annoncé dans la PR : stats de A, avant max(sources, côté Sell) = 520, après profil défaut = 500.

## Cas limites
- Profil absent : défauts, aucune écriture à la lecture. Deux PATCH simultanés : dernier écrit gagne (une ligne par utilisateur, contrainte unique).
- Structure choisie qui n'a plus de prix synchronisés : valeurs inconnues et libellé de source conservé.
- Quantité 0 d'un type : ignoré, comme aujourd'hui (`valueQuantities`).
- Total d'une période avec un type sans prix : valeur totale inconnue, types manquants listés.

## Accès et fraîcheur
- Profil lu et écrit uniquement par son propriétaire (utilisateur courant, aucun identifiant dans l'URL). Cascade à la suppression de l'utilisateur.
- `structureId` : aucun contrôle d'appartenance (les prix de structure sont des données de marché synchronisées, comme `preferredMarketStructureId` et le paramètre de requête actuels) ; défaut d'accès préexistant déjà signalé au journal par #537, hors lot.
- Source des prix : synchronisation Jita ou structure existante, fraîcheur affichée comme aujourd'hui.

## Tests existants contredits ou modifiés
- `MiningBestValueCalculator` (et `MiningStats*ProviderTest`, `LedgerDailyStatsProviderTest`) : plus de maximum de sources.
- `MiningEntryCollectionProviderTest` : paramètres `structureId`/`exportTax` remplacés par le profil.
- `OreValueTableProviderTest` : défauts depuis le profil.
- Harnais site et chunk (`CorporationMiningExtractionHarness`, tests du recorder) : le lecteur reçoit un utilisateur.
- Front : `MiningMyMining.spec`, `useOreSale*.spec`, `oreSalePanel.spec`, `oreValueTabReprocessingProfile.spec` (localStorage remplacé par le profil serveur).

## Hors périmètre
- Source affichée sur le dashboard et les stats journalières, remplacement de `useMiningPricing` à rendre cohérent avec `unitValue` côté ledger (inclus : il s'aligne sur `unitValue`), section Minage de la page Paramètres (lot `ui-shell`), renommage de `MiningBestValueCalculator`, valorisation des opérations existantes (inchangée), préremplissage d'une nouvelle opération depuis le profil, recalcul des scans de site déjà enregistrés, taxe lunaire, base de prix de l'industrie.

## Glossaire
- Ajouts : **Profil de valorisation minière** (Mining valuation profile), **Source de valorisation** (Valuation source).

## Actions de production
Migration à lancer au déploiement (nouvelle table `user_mining_valuation_profiles`, colonne `valuation_source` de `mining_site_scans`) ; aucune commande console.
