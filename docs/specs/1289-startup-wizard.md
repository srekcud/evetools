# #1289 — Assistant de démarrage : stations, puis acheter ou fabriquer et où acheter

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`#stations`, `#acheter`). **Front seulement** : aucune route ni migration, le back-end (#1285 `GET /work-stations`, `PATCH workStations` ; #1287 `POST/DELETE /bom/{lineId}/sourcing-market` ; #1288 `GET /sourcing-decisions`) existe. Mercure : aucune action asynchrone (écritures synchrones, le topic `industry-project` du POST de marché existe déjà).

## Contexte

L'étape 1 (produits et BPC) est `NewProjectModal` : elle crée le projet en brouillon. Aujourd'hui `GroupIndustryList::handleProjectCreated` ouvre directement la page du projet. Le choix des stations, l'achat ou la fabrication d'un intermédiaire et le marché d'un achat n'ont aucun écran.

## Parcours

R1. Nouvelle route `/industry/projects/:id/setup` (nom `industry-project-setup`, vue `ProjectSetup.vue`, `requiresAuth`). Après la création, `handleProjectCreated` ouvre cette route au lieu de la page du projet. Un lecteur qui n'est ni propriétaire ni administrateur du projet est renvoyé à la page du projet. Lien « Passer, garder les conseillées » : page du projet, sans écriture.
R2. Barre d'étapes en haut, fixe au défilement : `1 Produits et BPC` (fait, non cliquable), `2 Stations`, `3 Acheter ou fabriquer, où acheter` ; à droite, `Suivant : acheter ou fabriquer ▶` à l'étape 2, `Valider et démarrer` à l'étape 3. Les deux boutons sont atteignables sans défiler.
R3. « Valider et démarrer » écrit puis ouvre la page du projet (statut inchangé : le démarrage du projet garde son flux existant, `draft → in_progress`).

## Étape 2 : stations (`ProjectSetupStations`)

R4. Une ligne par entrée de `workTypes[]` : libellé du type (i18n de `workType`), besoin (`jobs` jobs, `runs` runs), pastille d'activité (réaction orange, fabrication cyan, copie et invention violet, palette de `companionActivityStyles`). À droite, une carte par candidat de `rows[]` : bouton radio, nom de la station, badge « conseillée » sur `recommendedStructureId`, système (`solarSystemName`), puis Bonus matériaux (`materialBonusPercent`), Matériaux, Installation, Transport avec une ligne par trajet de `transportLegs` (`kind`, m³, coût), Total, Écart (« le moins cher » si `delta` = 0, sinon « + montant »). Montant `null` : « inconnu » avec la donnée manquante, jamais 0. `costsCompared` faux (copie et invention) : nom et système seulement, « coût non comparé ». Nom long tronqué avec `title`.
R5. Type `locked` : radios désactivés, `lockReason` affichée (« achat fait » ou « job lancé »). `choiceStale` : bandeau « la station choisie n'existe plus, la conseillée s'applique ».
R6. Sélection : `picks[workType]` sinon `chosenStructureId` sinon `recommendedStructureId`. `selectedStructureId`, `stationsPayload`, `stationsSummary` sont des fonctions pures de `projectSetup.ts`.
R7. Bandeau de synthèse : Coût du projet estimé (somme des `totalCost` des candidats choisis, types comparés seulement ; « + écart par rapport aux conseillées » ou « toutes les stations conseillées »), Transports (somme des `transportCost` choisis), Stations (nombre de stations distinctes choisies, copie et invention comprises, et leurs noms), boutons « Tout mettre sur les conseillées » et « Suivant ». Un total, un écart ou un transport est `null` dès qu'un montant choisi est `null`. Pied de page : tarif courier et tarif du pont lus de la réponse (`courierIskPerCubicMeter`, `jumpBridgeIskPerCubicMeter`).

## Étape 3 : acheter ou fabriquer, où acheter (`ProjectSetupSourcing`)

R8. Une ligne par entrée de `rows` de `GET /sourcing-decisions`, trois sections : « Composants T2 et coques » (`stage` `component`, `hull`), « Réactions composites » (`reaction`), « Matières à acheter » (`base`, repliée par défaut avec « ＋ afficher les n lignes »). Colonnes : Fabriquer (`options.build`, absente pour une matière : « — »), Jita + import (`options.jita`), Marché de la capitale (`options.structure`). Cellule : radio, prix par unité (`perUnit`) et badge « conseillé » si l'option est `recommended`, puis le total, et pour Fabriquer intrants (`price`) + installation + transport et le nombre de runs, pour Jita « dont import » (`transport`), pour la capitale `availableQuantity` en rouge « en vente seulement » quand `eligible` est faux (cellule rouge, sélectionnable). Nom du type tronqué avec `title`, sous-ligne : quantité et « pour <station> » (nom de la station de `consumerStructureIds`, lu de l'étape 2).
R9. Choix effectif d'une ligne : `picks[typeId]` sinon `chosen` (de la réponse), `null` si les deux manquent (aucune cellule cochée). Les achats imposés par les groupes de blacklist du projet n'ont pas d'option Fabriquer. Chaque changement de choix refait `GET /sourcing-decisions?buy=<ids>&build=<ids>` (`sourcingQuery` : `picks` à `build` → `build`, `jita` ou `structure` → `buy`, lignes `base` exclues) : les quantités et les lignes se recalculent côté serveur ; la dernière requête gagne.
R10. Synthèse : Coût livré (`totals.total` plus, pour chaque ligne achetée dont le marché choisi diffère du `chosen` de la réponse, `options[choisi].total − options[chosen].total` ; `null` si un montant manque), écart par rapport à la première réponse du chargement (sans requête = les conseillés), Achats à Jita (somme des `options.jita.total` des lignes à Jita, nombre de types, dont import), Achats à la capitale (idem). Le marché choisi est local : la requête ne porte que acheter ou fabriquer.

## Écriture

R11. « Valider et démarrer », dans l'ordre : (1) `PATCH /industry/projects/{id}` `{workStations: stationsPayload}` (une entrée par type non verrouillé ayant une station choisie, y compris les conseillées, pour que le plan garde la conseillée en regard) ; (2) si `blacklistAfter(project.blacklistTypeIds, rows, picks)` n'est pas `null` : `PATCH` `{blacklistTypeIds}` ; (3) pour chaque `marketsToWrite(rows, picks)` : `POST /industry/projects/{id}/bom/{lineId}/sourcing-market` `{market}`, `lineId` lu de `GET /bom` (ligne non-job du même `typeId`). Une ligne introuvable ou une erreur d'écriture arrête la séquence et s'affiche dans un bandeau, sans naviguer ; rien n'est annulé, une relance réécrit les mêmes valeurs.
R12. `blacklistAfter` : pour chaque ligne ayant `options.build` et un choix effectif connu, ajoute `typeId` si le choix n'est pas `build`, le retire sinon ; les autres types de la liste restent ; résultat trié sans doublon, `null` s'il égale la liste actuelle. `marketsToWrite` : les marchés (`jita` ou `structure`) explicitement choisis par l'utilisateur pour les lignes dont le choix effectif est un achat.

## Exemples chiffrés (tests rouges)

| Fonction | Entrée | Sortie |
|---|---|---|
| `stationsSummary` | réaction A 1 000 (conseillée) / B 1 200 ; composants C 500 (conseillée) / D 450 ; transport choisi 40 et 10 ; picks `reaction: B`, `components: D` | coût 1 650, écart 1 650 − 1 500 = 150, transport 50, stations B et D |
| `stationsSummary` | un `totalCost` choisi `null` | coût `null`, écart `null` |
| `stationsPayload` | type verrouillé, type sans station choisie, deux types libres | seulement les deux libres |
| `sourcingQuery` | picks `{11: build, 22: jita, 33: structure}`, 33 est une ligne `base` | `build [11]`, `buy [22]` |
| `blacklistAfter` | liste `[5, 7]`, ligne 7 passe à `build`, ligne 9 à `jita` | `[5, 9]` ; sans changement : `null` |
| `sourcingSummary` | `totals.total` 1 000, ligne choisie Jita 200, picks sur la capitale 150 | coût 950 ; première réponse 1 000, écart −50 |
| `marketsToWrite` | deux lignes achetées dont une avec pick `structure` | une seule écriture `structure` |

## Cas limites

- Aucun `workType` (plan vide) : message, bouton Suivant actif. `GET` en 422 : message explicite et « Passer ».
- Ligne `chosen` `null` : aucune cellule cochée, jamais un défaut inventé. Station inconnue : ligne inconnue, jamais recommandée (règle serveur).
- Un projet de non-propriétaire n'a pas d'assistant ; un projet déjà démarré non plus (redirigé vers sa page).

## Hors périmètre

Retrait de `ProjectBuyOrBuild` et de la comparaison « Structures » (à l'accord de Sylvain) ; Modifier les stations depuis le projet et plan qui garde les choix en regard des conseillées (#1290) ; colonne Stock du prototype (le serveur chiffre le stock d'intermédiaire comme un achat Jita) ; ligne fixe « Vente » du prototype (le nom du hub n'est pas dans la réponse) ; compagnon, page projet en flux.

## Décisions

D1. Le projet est créé à la fin de l'étape 1 (le back-end exige un projet) : l'assistant est une route qui suit la création, pas une modale à trois étapes.
D2. Les choix de station ne s'écrivent qu'à la validation (pas d'écriture par clic) ; quitter l'assistant laisse le plan automatique, donc les conseillées.
D3. « Démarrer » ne change pas le statut du projet : brouillon jusqu'au flux existant.
D4. Le marché d'un achat s'écrit par ligne de BOM une fois la blacklist appliquée (la ligne d'un intermédiaire acheté n'existe qu'après).
