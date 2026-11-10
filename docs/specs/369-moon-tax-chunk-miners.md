# Spec #369, lot 3 : mineurs et taxe d'un chunk lunaire passé (calendrier)

**Statut : option A choisie par Sylvain le 2026-11-10 (point 3 de #369, bloqué au lot 2).** Circuit `circuit:m`, `group:mining-ops`, technicité 3 (code Mining, accès gestionnaire). Back = specs `273-moon-mining-tax.md` et `274-corporation-moon-chunk-calendar.md`. Lots 1 et 2 mergés (PR 463, 519). Dernier point de #369 : la PR ne porte pas `Closes` car deux questions restent ouvertes pour Sylvain (colonnes corporation et alliance du CSV, validation serveur de l'attribution d'un don) ; il ferme l'issue.

**Principe.** Un relevé de taxe est par (personnage, raffinerie, période) et un don paie un relevé, pas un chunk. Aucun payé ni reste n'est donc calculé par chunk. Le chunk montre, par mineur : la taxe calculée sur ses enregistrements du chunk, et l'état du relevé de la période, libellé comme tel.

## Back

- **M1 — Endpoint.** `GET /api/mining/moon-tax/{corporationId}/chunks/{id}/miners`, ressource `MoonTaxChunkMinersResource` (`Get`, identifiant `id` = identifiant de l'extraction, `uriVariables` avec `Link` vers `MoonTaxCorporationResource` et `self`). Accès `MoonTaxManagerAccess::assertManages` (403 avec les rôles lus, 503 si illisible), comme les relevés : la taxe par personnage n'est pas visible de tout membre de la corporation. Extraction inconnue ou d'une autre corporation que C : 404. Chunk au statut `extracting` : 400 « This moon chunk has not arrived yet ».
- **M2 — Enregistrements du chunk.** Enregistrements du ledger (`moon_observer_records`) de la raffinerie du chunk dont `lastUpdated` (date UTC) est ≥ le jour de `chunkArrivalTime` et ≤ le jour de `naturalDecayTime`. Si deux chunks de la même raffinerie couvrent un même jour, l'enregistrement va au chunk dont l'arrivée est la plus récente à cette date (aucun enregistrement n'est compté deux fois). Un enregistrement hors de toute fenêtre n'appartient à aucun chunk.
- **M3 — Raffinerie sans réglage.** Sans `MoonTaxRefinerySetting` pour (C, raffinerie) : `refineryOpen = false`, `miners = []` (le ledger n'est pas synchronisé pour une raffinerie non ouverte). Le front le dit, il n'affiche jamais « aucun mineur » dans ce cas.
- **M4 — Mineur.** Un élément par personnage ayant au moins un enregistrement du chunk, trié par `chunkTax` décroissant (inconnue en dernier), puis par nom. Champs : `characterId`, `characterName` (`MoonTaxAllianceResolver::characterNames`, un appel, `null` si inconnu), `chunkTax` (`?float`), `unknownTypeIds`, `statements`.
- **M5 — Taxe du chunk.** `MoonTaxAssessor::assessAll` sur les enregistrements du chunk et le réglage de la raffinerie (mêmes règles que T8 : somme par `type_id`, taux par rareté, aucune somme partielle présentée comme totale : `chunkTax = null` et `unknownTypeIds` listés si un type est inconnu). Les prix sont les prix Jita buy du jour : la taxe d'un chunk est une estimation qui peut différer du relevé figé. La réponse porte `taxBasis = "current_prices"`.
- **M6 — Relevés de période.** Pour chaque mineur, un élément `statements` par période (hebdomadaire ou mensuelle, selon le réglage) contenant au moins un de ses enregistrements du chunk, du plus ancien au plus récent : `periodStart`, `periodEnd`, `reference` (`null` si provisoire), `status` (`MoonTaxStatus`), `tax`, `paid`, `remaining`, issus de `MoonTaxStatementReader::ofCorporation` (relevé de C, de la raffinerie, du personnage et de `periodStart`). Aucun relevé trouvé pour la période (période terminée et ledger pas encore relu 1 h après sa fin) : élément avec `status = null`, `tax`, `paid`, `remaining` à `null`. Un chunk à cheval sur deux périodes donne deux éléments.
- **M7 — Inchangé.** `CorporationMiningExtractionsProvider` et sa réponse, les relevés, le CSV, les dons, la vue mineur. Aucune migration, aucune nouvelle sync, pas de Mercure.

## Front

- **F1 — Accès.** Sous-onglet « Chunks lunaires » : pour un chunk de statut `ready` ou `exploded`, un bouton `data-miners-toggle` (vue liste, colonne propre ; vue calendrier, sous le statut du chunk) ouvre le panneau `MoonChunkMinersPanel`. Un chunk `extracting` n'a pas le bouton. Chargement à l'ouverture (composable `useMoonChunkMiners`), un chargement par chunk, pas de rechargement automatique.
- **F2 — Panneau.** `data-miners` : en-tête « Mineurs de ce chunk, taxe estimée au prix actuel » ; une ligne `data-miner-row` par mineur : nom (sinon `#id`), taxe du chunk (`formatIsk`, sinon « inconnue » avec les types), puis, par période, `data-miner-statement` : « Relevé de la semaine du <début> » ou « du mois de <mois> » (UTC), référence, statut (`MoonTaxStatusCell`, jamais la couleur seule), payé et reste de la période ; `status = null` : « Relevé pas encore établi ». Le libellé précise que payé et reste valent pour la période entière, pas pour le chunk.
- **F3 — États.** 403 : le panneau dit « Réservé aux comptables de la corporation » (`data-miners-denied`), sans données. 503 ou autre échec : bandeau d'erreur (`data-miners-error`), jamais « aucun mineur ». `refineryOpen = false` : « Taxe lunaire non configurée pour cette raffinerie ». Liste vide avec raffinerie ouverte : « Aucun minage enregistré sur ce chunk ».
- **F4 — i18n.** Clés `ledger.mining.moonChunks.miners.*` en `en.json` et `fr.json`, insertion par édition de texte. Types dans `useMoonChunkMiners.ts`.

## Exemples chiffrés

Raffinerie R ouverte, période hebdomadaire, chunk G : arrivée jeudi 2026-11-05 20:00 UTC, décroissance dimanche 2026-11-08 23:00 UTC. `now` = mardi 2026-11-10 12:00 UTC.

| # | Cas | Attendu |
|---|---|---|
| X1 | Dax : 100 de R64 le 2026-11-06, taux R64 = 10 %, prix 50 | `chunkTax` = 500, un élément `statements` (semaine du 2026-11-02) |
| X2 | Enregistrement le 2026-11-04 (veille de l'arrivée) | absent du chunk |
| X3 | Enregistrement le 2026-11-09 (lendemain de la décroissance) | absent du chunk |
| X4 | Enregistrement le 2026-11-05 (jour de l'arrivée) et le 2026-11-08 (jour de la décroissance) | tous deux comptés |
| X5 | Chunk G2 de R arrivant le 2026-11-08 20:00 ; enregistrement du 2026-11-08 | compté pour G2 seulement |
| X6 | Chunk d'arrivée le 2026-10-29, décroissance le 2026-11-02 ; enregistrements les 2026-11-01 et 2026-11-02 | deux périodes, deux éléments `statements` (semaines du 2026-10-26 et 2026-11-02) |
| X7 | Type sans prix compressé parmi les enregistrements | `chunkTax = null`, `unknownTypeIds` listés |
| X8 | Relevé de la semaine figé, payé 300 sur 500 | élément `status = partial`, `tax` 500, `paid` 300, `remaining` 200 |
| X9 | Période sans relevé | élément `status = null`, montants `null` |
| X10 | Raffinerie sans réglage | `refineryOpen = false`, `miners = []` |
| X11 | Utilisateur sans rôle `Accountant` | 403, aucune taxe |
| X12 | Extraction d'une autre corporation / inconnue | 404 |
| X13 | Chunk `extracting` | 400 |
| X14 | Front : 403 | `data-miners-denied`, aucune ligne |

## Hors périmètre

Payé et reste par chunk (aucune règle d'allocation), taxe figée par chunk, colonne ou total de taxe dans la liste des chunks, vue mineur d'un chunk, export CSV, notification, lien du chunk vers l'écran gestionnaire, validation de l'attribution d'un don (question ouverte du lot 2).
