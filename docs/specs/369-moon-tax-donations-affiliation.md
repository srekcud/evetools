# Spec #369, lot 2 : liste et attribution des dons, corporation et alliance du mineur

**Statut : validée en autonomie en mode nuit le 2026-11-09, à relire par Sylvain.** Circuit `circuit:m` (lot de l'issue `circuit:l`), `group:mining-ops`. Back = specs `273-moon-mining-tax.md` (T10, T13, T14) et `369-moon-tax-screens.md` (lot 1, mergé : PR 463). Issue partielle : pas de `Closes`.

**Livré ici** : liste des dons et attribution depuis l'écran gestionnaire ; colonnes corporation et alliance du tableau par mineur. **Bloqué (décision produit)** : lien avec le calendrier des chunks (voir « Blocage »).

## Back

- **D1 — Liste des dons.** `GET /api/mining/moon-tax/{corporationId}/donations`, nouvelle ressource `MoonTaxDonationEntryResource` (`GetCollection`, `uriVariables` avec `Link` vers `MoonTaxCorporationResource`, identifiant `journalId`, sans pagination). `MoonTaxDonationResource` et son `POST …/attribute` ne changent pas. Accès : `MoonTaxManagerAccess::assertManages` (403 avec les rôles lus, 503 si illisible), comme les relevés. Aucun don d'une autre corporation que C.
- **D2 — Contenu.** Dons de C dont `date` ≥ `now` − 90 jours (constante `HISTORY_DAYS`), du plus récent au plus ancien. Champs : `journalId`, `corporationId`, `date`, `amount`, `reason` (null si absent), `firstPartyId`, `firstPartyName` (null si inconnu), `attributedCharacterId`, `attributedCharacterName`, `attributedAt` (null tant que non attribué), `payerIsMiner` : vrai si le payeur (`attributedCharacterId`, sinon `firstPartyId`) est le personnage d'au moins un relevé de C, provisoire compris (`MoonTaxStatementReader::ofCorporation`). Noms : `MoonTaxAllianceResolver::characterNames` (un appel), inconnu = null.
- **D3 — Pas de migration, pas de nouvelle sync.** L'attribution reste synchrone : pas de Mercure.
- **A1 — Affiliation du mineur.** `MoonTaxStatementView` et `MoonTaxStatementResource` (liste gestionnaire seulement, pas `MoonTaxMineResource`, pas le CSV) gagnent : `recordedCorporationId` (relevé figé : `getRecordedCorporationId()` ; provisoire : `latestRecordedCorporationId`), `recordedCorporationName`, `allianceId`, `allianceName`, `affiliationKnown` (bool). Lecture : `MoonTaxAllianceResolver::affiliations(list<int> corporationIds)` (nouvelle méthode, `getWithCache('/corporations/{id}/')` pour `name` et `alliance_id`, puis `getWithCache('/alliances/{id}/')` pour `name`), un appel par corporation et par alliance distinctes.
- **A2 — Inconnu.** Corporation enregistrée absente (`null`), ESI en erreur ou champ de type inattendu : `affiliationKnown = false`, noms et alliance `null`, avertissement journalisé, jamais une alliance par défaut. Corporation lue sans `alliance_id` : `affiliationKnown = true`, `allianceId = null` (sans alliance). `alliance_id` sans nom lisible : `affiliationKnown = false`.
- **A3 — Inchangé.** `outsideAlliance`, les filtres `late` et `period`, le CSV (mêmes colonnes) et les réponses existantes de la vue mineur ne changent pas.

## Front

- **F1 — Colonnes.** `MoonTaxStatementsTable` : colonnes `corporation` et `alliance` après `character`. Corporation : `recordedCorporationName`, sinon `#id`, sinon « inconnue » si `recordedCorporationId` est null. Alliance : `allianceName` ; `affiliationKnown && allianceId === null` : « Sans alliance » ; `!affiliationKnown` : « inconnue ». Jamais une cellule vide pour un inconnu. Colonnes `data-cell="corporation"` et `data-cell="alliance"`.
- **F2 — Dons.** Sous le tableau de chaque corporation gérée, section `data-donations` « Dons reçus » (composant `MoonTaxDonationsPanel`, composable `useMoonTaxDonations(corporationId)`). Chargement à l'ouverture de la corporation (après l'accès accordé) et après chaque attribution ou sync terminée. Filtre à deux boutons `aria-pressed` : « À attribuer » (défaut, `payerIsMiner === false`) et « Tous ».
- **F3 — Ligne de don.** `data-donation-row` : date UTC, donateur (`firstPartyName`, sinon `#firstPartyId`), montant (`formatIsk`), motif (`reason`, sinon « — »), attribution (nom ou `#id` du personnage et date, sinon « Non attribué »), sélecteur `data-attribute-select` des mineurs de la corporation (personnages distincts des relevés chargés, `characterName` sinon `#id`, triés par nom) et bouton `data-action="attribute"` désactivé tant qu'aucun mineur n'est choisi ou pendant l'appel. Sélecteur précoché sur le personnage déjà attribué.
- **F4 — Appel.** `POST /mining/moon-tax/{corporationId}/donations/{journalId}/attribute` avec `{characterId}`. Succès : rechargement des dons ET des relevés (le payé change). Échec : message du serveur sur la ligne (`data-donation-error`), sélection conservée.
- **F5 — États.** Chargement en échec ou réponse sans tableau : bandeau d'erreur dans la section, jamais « aucun don ». Liste vide avec le filtre « À attribuer » : « Aucun don à attribuer » ; avec « Tous » : « Aucun don sur 90 jours ». Une section de corporation en 403 ou 503 n'affiche pas les dons.
- **F6 — i18n.** Clés `ledger.mining.moonTax.donations.*` et `ledger.mining.moonTax.columns.corporation|alliance` en `en.json` et `fr.json`, insertion par édition de texte. Types `MoonTaxStatement` et `MoonTaxDonationEntry` dans `useMoonTax.ts`.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| X1 | Don 7001 de Dax (relevé existant), don 7002 d'un alt inconnu | liste : 7002 `payerIsMiner=false`, 7001 `true` ; filtre « À attribuer » : 7002 seul |
| X2 | Don de 100 jours | absent de la liste |
| X3 | Utilisateur sans rôle `Accountant` | 403, aucun don |
| X4 | Don d'une autre corporation | absent |
| X5 | Relevé figé `recordedCorporationId` = N, N dans l'alliance A | `recordedCorporationName`, `allianceId` = A, `allianceName`, `affiliationKnown=true` |
| X6 | N sans `alliance_id` | `allianceId=null`, `affiliationKnown=true` |
| X7 | ESI en erreur sur `/corporations/N/` | `affiliationKnown=false`, noms null, le relevé reste listé |
| X8 | Attribuer 7002 à Dax | POST, puis rechargement des dons et des relevés |
| X9 | POST en 404 | message du serveur sur la ligne, sélection conservée |
| X10 | Corporation sur 403 | pas de section de dons |

## Blocage (point 3, calendrier)

Un chunk passé du calendrier doit montrer « qui l'a miné et la taxe due, payée, restante ». Les relevés sont par (personnage, raffinerie, période hebdomadaire ou mensuelle) et les dons paient un relevé, pas un chunk : un chunk peut chevaucher deux périodes et une période en contient plusieurs. Le payé et le reste par chunk exigent une règle d'allocation qui n'existe pas. Options et recommandation dans le journal et sur l'issue.

## Hors périmètre

CSV (colonnes inchangées), vue mineur, lien calendrier, filtre des dons par période, annulation d'une attribution, validation côté serveur que le personnage attribué est un mineur de C (le sélecteur le garantit côté écran ; question notée), notifications.
