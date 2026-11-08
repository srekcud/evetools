# Spec #369 — Minage lunaire : écrans de la taxe lunaire (lot 1 : gestionnaire et mineur)

**Statut : validée en autonomie en mode nuit le 2026-11-08, à relire par Sylvain.** Circuit `circuit:l`, `group:mining-ops`. Back = spec `273-moon-mining-tax.md` (livré). Maquette retenue : `mockups/grouped-mining-v4.html`, vue `tax` (onglets « Gestionnaire » et « Ma taxe »). Direction design HUD EVE, comme `MoonChunksTab`.

**Tranche livrée** : correctif d'affiliation du contrôle d'accès gestionnaire, écran gestionnaire, écran mineur. **Reste, noté en Décisions** : attribution d'un don (aucun endpoint de liste des dons : `POST …/donations/{journalId}/attribute` seul existe), colonnes corporation et alliance du mineur (absentes de `MoonTaxStatementResource`), lien avec le calendrier (#274 pas dans `origin/main`). Issue partielle : pas de `Closes`.

## Back (B)

- **B1 — Affiliation relue.** `MoonTaxManagerAccess::assertManages` ne se fie plus à `Character::getCorporationId()`. Pour chaque personnage du compte (`isAuthValid`, scope des rôles), la corporation courante est lue par l'ESI public `GET /characters/{id}/` (nouvelle méthode `CharacterService::readCorporationId(int): int`, cache ESI, champ manquant ou illisible = `UnexpectedValueException`). Seuls les personnages dont la corporation lue égale C sont éligibles pour le rôle `Accountant`. Une affiliation illisible compte comme personnage illisible (503 si aucun accès n'est accordé), jamais un accès par défaut. Les 403 listent toujours les rôles lus des seuls personnages éligibles.

## Front (F)

- **F1 — Emplacement.** Nouveau sous-onglet `moonTax` de `MiningTab` (après `moonChunks`), composant `MoonTaxTab`, deux vues : `manager` (défaut) et `miner`, boutons à `aria-pressed`. Composable `useMoonTax` (types, appels, états), composants sous `components/ledger/moon-tax/`.
- **F2 — Gestionnaire, corporations.** Les corporations viennent de `GET /mining/corporation-extractions` (ses raffineries, avec nom de structure, servent de liste des raffineries configurables). Pour chacune : `GET /mining/moon-tax/{corporationId}`. Nom de corporation : personnage du compte, sinon « Corporation {id} ».
- **F3 — États d'accès.** 403 : panneau « accès gestionnaire refusé » avec le message du serveur (qui porte les rôles lus), ni tableau ni réglages. 503 : panneau « rôles illisibles » avec le message. Autre erreur : bandeau d'erreur. Jamais un tableau vide à la place d'un refus.
- **F4 — États du ledger et des paiements.** `access.ledger` et `access.payments` : `ok` rien ; `never_synced` « pas encore synchronisé » ; `denied` « accès refusé » ; `reconnect` « à reconnecter » (cite le scope `esi-wallet.read_corporation_wallets.v1` pour les paiements) ; `error` « erreur ESI ». Avec une date `observersSyncedAt` / `walletSyncedAt` : « dernière lecture le … ». Les relevés déjà connus restent affichés sous le bandeau. Le message « aucun relevé » n'apparaît que si `access.ledger` vaut `ok`.
- **F5 — Réglage d'une raffinerie.** Un formulaire par raffinerie connue : ouverte à l'alliance (case), période `weekly`/`monthly`, cinq taux R4 à R64 (0 à 100, deux décimales), division du wallet (1 à 7). Valeurs initiales : réglage existant, sinon raffinerie fermée, période hebdomadaire, taux vides (tous requis, bouton désactivé tant qu'un taux manque). `PUT /mining/moon-tax/{corporationId}/refineries/{structureId}`. 422 : message du serveur sous le formulaire, valeurs conservées. Succès : confirmation et rechargement de la corporation.
- **F6 — Tableau par mineur.** `GET /mining/moon-tax/{corporationId}/statements` (filtre `late=true` quand « En retard » est actif ; boutons Tous / En retard). Colonnes : personnage (`characterName`, sinon `#characterId`), raffinerie, période (`periodStart` à `periodEnd`, dates UTC), référence, dû, payé, reste, statut, « hors alliance » si `outsideAlliance === true`, actions. `tax` ou `remaining` `null` : texte « inconnue » avec les `unknownTypeIds` (« sans prix : … »), jamais 0 ni vide. Libellés de statut pour `provisional`, `unknown`, `cancelled`, `paid`, `late`, `partial`, `due`. Un statut inconnu est affiché tel quel, jamais masqué.
- **F7 — Totaux.** Quatre cartes : dû, payé, reste, hors alliance (nombre de mineurs). Sur les relevés non annulés. Si au moins une taxe est `null`, le dû et le reste affichent « inconnu » avec le total des parties connues en sous-titre, jamais une somme partielle présentée comme totale.
- **F8 — Export CSV.** Bouton « Exporter CSV » : même requête avec `Accept: text/csv` (par `authFetch`), téléchargement `moon-tax-{corporationId}.csv` (filtre `late` respecté). Échec : bandeau d'erreur.
- **F9 — Actions.** « Marquer payé » (`POST …/statements/{id}/mark-paid`) pour les statuts `due`, `late`, `partial` ; « Annuler » (`POST …/statements/{id}/cancel`) pour les relevés ni `cancelled` ni `provisional`, avec une confirmation en deux temps (bouton « Confirmer l'annulation », car sans retour arrière). Relevé sans `id` (provisoire) : aucune action. Succès : rechargement du tableau. Échec : message du serveur sur la ligne.
- **F10 — Synchronisation et Mercure.** Bouton « Synchroniser » par corporation : `POST …/sync` (corps `{}`), désactivé pendant la sync. Progression lue dans `useSyncStore().moonTaxProgress` (topic `/user/{userId}/sync/moon-tax`, déjà déclaré par `MercurePublisherService::getTopicsForUser()`), libellé avec le pourcentage quand il est connu. `completed` : rechargement de la corporation et du tableau ; `error` : message d'erreur et bouton réactivé.
- **F11 — Vue mineur.** `GET /mining/moon-tax/mine`, groupée par corporation. Colonnes : période, raffinerie, personnage (`#characterId`, nom du compte si connu), dû, payé, reste, statut, référence `TAX-…` avec bouton « Copier » (`navigator.clipboard`, retour visuel « Copié ») ; `provisional` : « période en cours », pas de référence. `tax` `null` : « inconnue » + « sans prix : ids ». Un encadré « Comment payer » : don en jeu à la corporation, avec la référence en motif ; la reconnaissance suit la sync du journal (cache 1 h) ; payé depuis un alt, le gestionnaire rapproche à la main. Aucun relevé : « Aucune taxe lunaire pour vos personnages ». Vue ouverte à tout utilisateur connecté.
- **F12 — Noms.** Nom de structure d'après `corporation-extractions`, sinon `Structure {id}` (repli explicite, pas de valeur plausible).
- **F13 — i18n.** Clés `ledger.mining.moonTax.*` en `en.json` et `fr.json`, insérées par édition de texte. Montants par `formatIsk`.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| X1 | `GET …/{C}` répond 403 « role required … Roles read: Ilyra (Director) » | Panneau d'accès refusé avec ce message, aucun tableau |
| X2 | `access.ledger = denied`, `observersSyncedAt = 2026-10-28T19:40Z`, 2 relevés | Bandeau « accès refusé », dernière lecture 2026-10-28 19:40, les 2 relevés restent |
| X3 | `access.ledger = never_synced`, aucun relevé | « pas encore synchronisé », pas « aucun relevé » |
| X4 | Relevés tax 6 000 000 (payé 4 000 000) et `null` (types 1, 2) | Reste « inconnu » et « dont 2 000 000 connus » ; ligne 2 : « sans prix : 1, 2 » |
| X5 | Relevé `due`, clic Marquer payé | POST `mark-paid`, puis nouveau GET des relevés |
| X6 | Clic Annuler | Bouton « Confirmer l'annulation » ; aucun POST avant la confirmation |
| X7 | Taux R16 vide | Bouton Enregistrer désactivé |
| X8 | PUT répond 422 | Message sous le formulaire, valeurs conservées |
| X9 | `moonTaxProgress` passe à `completed` | Rechargement ; `error` : bouton réactivé et message |
| X10 | Mineur : relevé `TAX-2026-W44-7F3K`, reste 6 000 000 | Référence affichée, « Copier » écrit la référence dans le presse-papiers |
| X11 | B1 : stocké corp C mais ESI public dit corp D | Personnage non éligible, 403 si aucun autre |
| X12 | B1 : `GET /characters/{id}/` en erreur | 503, jamais d'accès accordé |

## Cas limites

- Corporation sans raffinerie connue : message « aucune raffinerie connue, synchronisez les extractions », pas de formulaire.
- Réponse sans tableau `refineries` ou `statements` : erreur de chargement, jamais une liste vide.
- Deux corporations gérées : deux sections indépendantes (un 403 sur l'une n'efface pas l'autre).

## Hors périmètre

Liste et attribution des dons, colonnes corporation et alliance du mineur, lien chunk passé du calendrier (#274), filtre par période, relances et notifications, reconnexion guidée (annoncer au déploiement le scope `esi-wallet.read_corporation_wallets.v1`), tout changement du back de #273 hors B1.
