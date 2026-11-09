# Import du Mining Ledger par copier-coller — idées en réserve

Statut : idée, pas d'issue. Capturé le 2026-11-05 pour anticiper un besoin de collage de ce type.

## Échantillon réel (fenêtre Mining Ledger personnelle, copie en jeu)

Séparateur tabulation, en-tête inclus :

```
Timestamp	Ore Type	Quantity	Residue Quantity	Volume	Residue Volume	Est. Price	Est. Residue Price	Solar System	Ore TypeID	SolarSystemID
2026.10.17	Krystallos	101	0	101000.0	0.0	45693397		3AE-CP	16269	30000798
2026.10.17	Glare Crust	55	0	55000.0	0.0	11021417		3AE-CP	16266	30000798
2026.10.17	Glacial Mass IV-Grade	18	0	18000.0	0.0	2039276		3AE-CP	17977	30000798
2026.10.17	Dark Glitter	114	0	114000.0	0.0	31746316		3AE-CP	16267	30000798
2026.08.22	Krystallos	91	0	91000.0	0.0	41169299		3AE-CP	16269	30000798
2026.08.22	Glare Crust	192	0	192000.0	0.0	38474766		3AE-CP	16266	30000798
2026.08.22	Dark Glitter	761	0	761000.0	0.0	211920586		3AE-CP	16267	30000798
```

Observations sur le format :
- Date `YYYY.MM.DD` (jour seulement, même granularité que l'ESI).
- `Est. Price` = total de la ligne, pas unitaire (Krystallos : 45 693 397 / 101 ≈ 452 410 ISK/u).
- `Est. Residue Price` peut être vide alors que `Residue Quantity` vaut 0 : vide = inconnu, jamais 0 (règle CLAUDE.md).
- Volumes en flottant avec `.` ; quantités en entier, sans séparateur de milliers ici (à vérifier sur un client FR et sur de gros volumes).
- `Ore TypeID` et `SolarSystemID` sont présents : pas besoin de résoudre les noms (les noms servent seulement de contrôle de cohérence avec la SDE).
- Aucun personnage dans le collage : l'utilisateur choisit le personnage à l'import.
- L'échantillon est de la glace (1 000 m³/u). Vérifier un collage de minerai et de minerai lunaire, et un collage avec du résidu non nul.
- `Residue` = gaspillage de minage, absent de l'ESI `/characters/{id}/mining/`.

## Idées, par valeur estimée

1. **Historique au-delà de l'ESI** : l'ESI ne renvoie que ~30 jours, la fenêtre en jeu remonte plus loin (août dans l'échantillon). Un import comble l'historique avant la première sync ou après une période sans connexion.
2. **Suivi du résidu (gaspillage)** : nouvelle donnée, inaccessible par l'ESI. Taux de gaspillage par type, par système, par période ; ISK perdus (`Est. Residue Price`). Utile pour juger cristaux, modules et skills.
3. **Comparaison de prix** : `Est. Price` en jeu face à notre `totalValue` (Jita / structure / meilleur prix de `MiningBestValueCalculator`). Afficher l'écart, sans remplacer notre valorisation.
4. **Réconciliation ESI / jeu** : signaler les jours où la quantité collée diffère de la quantité synchronisée (sync ratée, pagination tronquée, cf. proposition #10 de `issues-proposals.md`).
5. **Import pour un perso sans token** : alt non enregistré, perso dont le token est révoqué, ou ami de la corpo qui colle son ledger dans un projet Group Industry ou un futur Fleet Tracker (contribution minière).
6. **Prévisualisation avant import** : tableau des lignes parsées, état par ligne (nouvelle / déjà synchronisée identique / différente / invalide), puis confirmation.

## Points de conception à trancher le moment venu

- **Dédoublonnage** : la clé existe déjà, `MiningEntryRepository::findByUniqueKey(user, characterId, date, typeId, solarSystemId)`. Ne jamais additionner une ligne collée à une ligne synchronisée.
- **Qui gagne** : `MiningSyncService::upsertMiningEntry` écrase la quantité à chaque sync. Une ligne importée sur une date encore couverte par l'ESI sera réécrite par la sync suivante : acceptable (l'ESI est la source), mais le résidu importé ne doit pas être effacé.
- **Origine de la ligne** : ajouter une colonne source (`esi` / `paste`) pour l'affichage et la réconciliation.
- **Schéma** : `residueQuantity` et `residueEstimatedValue` nullables sur `MiningEntry` (null = inconnu) ; éventuellement `inGameEstimatedValue` nullable.
- **Parsing** : s'appuyer sur l'en-tête (ordre et langue des colonnes à vérifier sur un client FR/DE), pas sur des positions fixes. Rejeter explicitement une ligne invalide plutôt que l'ignorer en silence. `ItemParserService` gère un autre format (nom + quantité) : parser dédié.
- **Endpoint** : API Platform, `POST /api/me/mining/import` (nom à valider avec les ressources existantes de `src/ApiResource/Ledger/`), avec un mode prévisualisation.
- **Usage** : les lignes importées démarrent en `USAGE_UNKNOWN`, comme celles de la sync.
- **Glossaire** : ajouter « résidu » / « residue » à `docs/glossary.md` avant de nommer les symboles.

## Tests à prévoir

- L'échantillon ci-dessus en fixture (7 lignes, 2 dates, prix total et non unitaire, résidu vide = null).
- Collage sans en-tête, colonnes réordonnées, ligne tronquée, séparateur de milliers, CRLF.
- Ligne déjà synchronisée identique, différente, absente.
