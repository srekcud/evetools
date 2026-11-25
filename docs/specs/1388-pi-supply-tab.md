# Spec #1388 — Onglet Approvisionnement (remplissage des launchpads pour les N prochains jours)

Mode nuit : validée en autonomie. Empilée sur #1382 (PR #1413) et #1377 (PR #1408). Front seul : aucune migration, aucune sync, aucune ressource API nouvelle (donc ni Mercure ni ESI). Lecture de `GET /api/planetary/input-needs` (#1380, #1382).

## But

Le joueur tient un tableur pour remplir les launchpads des planètes usines avant chaque tournée. L'onglet calcule, par launchpad, la quantité de chaque entrée à apporter pour tenir N jours, en tenant compte de la capacité.

## Règles

1. **Onglet** : `supply` (libellé « Supply » / « Approvisionnement »), placé entre Colonies et Revenus (`planner`, `colonies`, `supply`, `revenue`). Colonies reste l'onglet par défaut ; `?tab=supply` ouvre l'onglet.
2. **Données** : un store Pinia `planetarySupply` charge `/planetary/input-needs` à l'ouverture de l'onglet et à chaque changement de `planetaryStore.lastSyncAt` (fin de synchro). État : données, `isLoading`, `error`. Une erreur d'appel est affichée dans un `ErrorBanner`, jamais remplacée par une grille vide.
3. **Paramètre N** (« je repasse dans N jours ») : local à la page, défaut `4`, non persisté. Valide si fini, > 0 et ≤ 90 (constante nommée). Invalide : message sous le champ, aucune ligne calculée.
4. **Lignes** : une par launchpad de `launchpads[]` dont `feeds` n'est pas vide (un launchpad sans entrée alimentée n'apparaît pas). Libellé : nom de planète, type de launchpad.
5. **Remplissage équilibré** (fonction pure `planLaunchpadRefill(launchpad, days)`) :
   - `dailyVolume = Σ dailyRate × volumePerUnit` des feeds ; `capacityDays = capacityM3 / dailyVolume` ;
   - `balancedDays = min(N, capacityDays)` ; `limited = capacityDays < N` ;
   - quantité d'un feed = `max(0, ceil(dailyRate × balancedDays − projectedStock))` : le stock sur place est `projectedStock` (projeté à maintenant, #1382), jamais `stock` ;
   - `bringM3 = Σ quantité × volumePerUnit` ;
   - `autonomyDays` après le plein = minimum sur les feeds de `(projectedStock + quantité) / dailyRate` (feeds à débit 0 ignorés ; null s'ils le sont tous).
6. **Inconnu reste inconnu** : un launchpad dont `capacityM3` est null ou dont un feed a `volumePerUnit` null est une ligne `unknown` : cellules « inconnu », aucune quantité, aucun m3, exclue des totaux. Un message au-dessus de la grille compte les lignes exclues. Jamais de `?? 0`.
7. **Grille** : une colonne par type d'entrée présent dans les lignes calculables ou inconnues (triées par `typeId`, icône SDE et nom en en-tête), cellule vide quand le launchpad ne consomme pas ce type. Colonnes de fin : m3 à apporter, autonomie après le plein (jours, une décimale). Dernière ligne : total par type (somme des quantités) et total m3.
8. **Alerte de capacité** : pour chaque ligne `limited`, un avertissement (planète, jours tenus au plus avec `capacityDays`, N demandé) et la cellule d'autonomie en orange. Si N est tenu partout, aucun avertissement.
9. **« Copier le chargement »** : copie `multibuyText` des totaux par type (quantité > 0 seulement, nom de type, une ligne par type, via `@/utils/multibuy`) ; retour visuel via `useCopyFeedback`. Désactivé si la liste est vide. Un type dont `typeName` est null est exclu de la copie et signalé par un message (aucun nom de repli) ; la grille l'affiche `#typeId`.
10. **États** : chargement (spinner) ; erreur (banner) ; aucun launchpad alimentant une usine : message vide explicite. `complete === false` : avertissement « certaines usines ont un schéma inconnu, des entrées peuvent manquer ».
11. **Aucun achat ici** : pas de colonne de prix ni de manque. Le texte d'aide dit que les quantités sont à apporter, les achats se feront dans l'onglet Stock (livré par #1395, pas de lien tant qu'il n'existe pas).
12. **i18n** FR et EN, clés `pi.supply.*` et `pi.tabs.supply`, parité vérifiée par un test de clés (modèle `planetaryPlannerKeys.spec.ts`).
13. **Glossaire** : ajouter « Remplissage équilibré de launchpad » / « Balanced launchpad refill ».

## Exemples chiffrés (volume 0,19 m3, capacité 10 000 m3)

| Cas | Entrée | Sortie |
|---|---|---|
| 4 entrées à 2 880/j, N = 7, stock 0 | débit 2 188,8 m3/j | `capacityDays` 4,5687 ; `limited` ; 13 158 par entrée ; 9 999,6 m3 |
| Même launchpad, N = 4, stock projeté 0 | | `balancedDays` 4 ; 11 520 par entrée ; 8 755,2 m3 ; autonomie 4,0 |
| Même, N = 4, stock projeté 2 000 | | 9 520 par entrée |
| Stock au-dessus de la cible | stock projeté 20 000, débit 2 880, N = 4 | quantité 0 pour cette entrée |
| 2 entrées à 9 600/j, N = 7 | 3 648 m3/j | `capacityDays` 2,74 ; `limited` |
| Stock mesuré 5 000, projeté 3 000 | débit 2 880, N = 4 | la quantité part de 3 000, pas de 5 000 |
| Volume inconnu | un feed `volumePerUnit` null | ligne `unknown`, hors totaux |
| Capacité inconnue | `capacityM3` null | ligne `unknown` |
| Launchpad sans feed | `feeds: []` | aucune ligne |
| Totaux | deux launchpads, entrée commune à 11 520 + 9 520 | total du type 21 040 |
| N = 0 / -1 / 91 / NaN | | invalide, aucune ligne |

## Cas limites

- Tous les débits à 0 : `dailyVolume` = 0, `capacityDays` infini, `balancedDays` = N, quantités = 0 − stock → 0.
- Un launchpad rempli au-delà de sa capacité par du stock hors feeds n'est pas modélisé (le stock d'un type non alimenté n'est pas déduit de la capacité).
- Plusieurs personnages : toutes les colonies du joueur ; pas de filtre par personnage dans ce lot.

## Hors périmètre

- Sources internes (« à ramasser ») et aller-retour : #1389. Stock en customs office : #1390. Alertes : #1391.
- Filtre par personnage de la maquette, détail visuel de remplissage par launchpad (jauge), liens vers l'onglet Stock.
- Modification de `VOLUME_BY_TIER` / `usePlanetaryHelpers.ts` (volumes corrigés par #1377).

## Décisions prises en autonomie

- Le stock de départ est `projectedStock` (plancher) : on apporte plus plutôt que de promettre une autonomie fausse (cohérent avec #1382).
- Arrondi à l'unité supérieure par entrée (on n'apporte pas de fraction) ; l'écart de capacité est inférieur à une unité.
- Limite de N à 90 jours : au-delà, la capacité d'un launchpad rend le calcul sans objet ; question à confirmer par Sylvain.
- Pas de lien vers l'onglet Stock avant #1395 (onglet inexistant).
- `typeName` null : exclu de la copie multibuy avec message, `#typeId` dans la grille.

## Tests

- Unit vitest `planetarySupply` : un cas par règle 5 à 9 et le tableau ci-dessus.
- Store : chargement, erreur, rechargement à la fin d'une synchro.
- Composant : onglet affiché avec grille et totaux, alerte de capacité, ligne inconnue, copie, état vide, N invalide, onglet ouvert par `?tab=supply`.
- Clés i18n FR/EN identiques.

## Note de câblage

Backend : aucun changement. Réponse `/planetary/input-needs` : `launchpads[]` = `colonyId` (string|null), `planetId`, `planetName`, `pinId` (number), `typeId`, `typeName` (null possible), `capacityM3` (null possible), `usedVolumeM3`, `dailyVolumeM3` (ne pas utiliser, recalculer), `lastViewAt`, `dataAgeSeconds`, `dataStale`, `feeds[]` = `typeId`, `typeName` (null possible), `dailyRate`, `stock`, `projectedStock` (décimal possible), `emptyAt`, `volumePerUnit` (null possible). `complete: boolean` au premier niveau.

Créer : `frontend/src/utils/planetarySupply.ts` (types, `planLaunchpadRefill`, `MAX_SUPPLY_DAYS = 90`, `DEFAULT_SUPPLY_DAYS = 4`, validation de N, agrégat de grille), `frontend/src/stores/planetarySupply.ts` (modèle `stores/planetaryPlanner.ts`, `apiRequest`, `isLoading`, `error`, `clearError`), `frontend/src/components/planetary/PiSupplyTab.vue` (`onMounted` charge ; `watch(() => planetaryStore.lastSyncAt)` recharge).
Modifier `frontend/src/views/PlanetaryInteraction.vue` : type `PlanetaryTab` et `PLANETARY_TABS` = `['planner','colonies','supply','revenue']`, `<PiSupplyTab v-else-if="activeTab === 'supply'" />` ; `useTabQuery` gère `?tab=supply`, défaut `colonies`.
Réutiliser : `multibuyText` (`@/utils/multibuy`, lignes `{typeName: string, quantity}`), `useCopyFeedback()` (`copied`, `failed`, `copy(text)`), `useEveImages().getTypeIconUrl(typeId, 32)`, `useFormatters().formatNumber`, `ErrorBanner`, `LoadingSpinner`.
i18n : `pi.tabs.supply` dans `en.json` et `fr.json` (l.367) ; `pi.supply` existe déjà (`inputs`, `output`, `supply`, utilisé par `PiProductionTable.vue`) : ajouter les clés à côté, sans remplacer l'objet. Test de parité : copie de `tests/i18n/planetaryPlannerKeys.spec.ts` tolérant ces trois clés.
Tests existants à adapter : `tests/views/PlanetaryInteractionPlannerTab.spec.ts:97` (liste exacte des onglets : insérer `supply` entre colonies et revenue). Modèle de vue : `mountViewAnswering` (même fichier, l.56-83). Fixtures : copie de `tests/planetaryPlannerFixtures.ts` en `tests/planetarySupplyFixtures.ts`. Emplacements : `tests/utils/`, `tests/stores/`, `tests/components/planetary/`.
Pièges : jamais `?? 0` sur `capacityM3` / `volumePerUnit` ; départ = `projectedStock` puis `ceil` ; capacité infinie (débits à 0) n'est pas `limited` ; clé `v-for` = `${colonyId}-${pinId}` ; `fetchColonies` modifie `lastSyncAt` au premier chargement donc deux appels au montage possibles (tester qu'un appel part après `finishSync`).
