# #1171 — Companion HUD redesign with activity groups

Front only. No API resource, no migration, no Mercure topic. Maquette retenue : `docs/design/mockups/group-companion.html` (colonne 360×640, barre 1024×72 ; `companionWindowSize.ts` garde ses valeurs, la barre doit tenir dans 1024 de large).

## Contexte
`GroupCompanion.vue` empile des sections de texte. Tout ce que la maquette montre sort déjà de `CompanionTasks`, des `BomItem` et de `JobMatch.endDate`. La logique va dans `companionTasks.ts` (fonctions pures), le composant n'affiche que.

## Règles
R1 **Groupe d'activité** d'une ligne (`activityGroupOf`) : `!isJob` → `material` ; `isJob` avec `activityType` ∈ `copy | invention | reaction | manufacturing` → ce groupe ; sinon (null ou valeur inconnue) → `unknown`, jamais replié dans un autre groupe. Ordre d'affichage : copy, invention, reaction, manufacturing, material, unknown (le terme `unknown` ferme la liste).
R2 **Regroupement** (`groupByActivity`) : tri stable par ordre R1, l'ordre de chaîne existant (`inChainOrder`) est conservé à l'intérieur d'un groupe ; seuls les groupes non vides sortent. Utilisé pour les trois listes (À attribuer, À livrer, Mes jobs).
R3 **Plafond de la liste libre** : 6 lignes au total, prises dans l'ordre trié de R2 (plus 6 par sous-groupe buy/build). Le reste = `free.length - 6`, lien « + N autres lignes libres · voir la BOM » (émet `open-bom`).
R4 **Avancement par activité** (`activityProgress`, sur tous les `bomItems`) : pour chaque groupe R1 non vide, `{ group, delivered, total, percent }` avec `delivered` = lignes `isFulfilled`, `percent = round(delivered / total × 100)`. Calcul par nombre de lignes (des quantités de types différents ne s'additionnent pas). Un groupe sans ligne n'a pas de barre.
R5 **Pied** : `deliveredLines / totalLines` (toutes les lignes de BOM, `isFulfilled`), `freeValue = { total, unknownLines }` sur les lignes libres de matériau (`!isJob`) : `total` = somme de `freeQuantity × estimatedPrice` des lignes au prix connu, `unknownLines` = lignes au prix `null`. Affichage : le total, suivi de « + N sans prix » si `unknownLines > 0`, jamais un 0 pour un prix inconnu ; si toutes les lignes libres de matériau sont sans prix, le total est affiché comme inconnu.
R6 **Produit** : `tasks.product = { typeId, typeName, runs, otherCount }` du premier `project.items`, `otherCount` = nombre d'autres produits ; `null` sans produit. En-tête : rendu CCP (`useEveImages().getTypeRenderUrl`, nouveau, `types/{id}/render?size=`), runs en badge, infobulle = nom du projet. Nom du projet tronqué avec infobulle.
R7 **Jauge BOM** : anneau SVG circulaire (`bomPercent`, bornée 0..100 pour le tracé), texte `N%` avec `data-testid="companion-bom-percent"`, infobulle `bomFulfillment`. Remplace « BOM N % » et la section Projet. `finalJobsRunning` passe dans la sous-ligne d'en-tête, à côté du témoin « direct ».
R8 **Chronométrage d'un job** (`jobTimingOf(match, now)`) : état `ready` si `status = ready` ou (`active` et `endDate` ≤ now) ; `paused` si `status = paused` ; sinon `running`. `remainingMs = max(0, endDate − now)` (0 pour `ready`), `null` si `endDate` null (affiché « inconnu », jamais 0). Pas de date de début exposée par l'API : aucune barre de progression pour `running` et `paused`, seul le compte à rebours est affiché ; un job `ready` porte la barre pleine verte. Rendu du compte à rebours sans secondes (l'horloge de l'hôte bat toutes les 30 s) : `HH:MM` sous 24 h, `J j HH h` au-delà.
R9 **Alertes actionnables** : l'ordre de priorité ne change pas ; la plus prioritaire est en haut. `job_ready` porte un bouton « Livrer » (émet `deliver` avec l'id de la ligne du job) ; `undercut_sell_order` porte un bouton « Copier » (copie `suggestedPrice` de la première commande dans le presse-papier de la fenêtre qui rend, comme `BlueprintNameCopyButton`) seulement si `suggestedPrice !== null`, avec confirmation « Copié » ou « Échec » ; les autres alertes n'ont pas de bouton. La zone d'alertes de la colonne défile au-delà de ~2 alertes, jamais le reste de la fenêtre. Le halo de nouvelle alerte (`companion-pulse-*`, 3 fois puis immobile, `prefers-reduced-motion` respecté) est conservé tel quel.
R10 **Onglets** (colonne) : À attribuer / À livrer / Mes jobs, chacun avec son compteur (`free.length`, `mine.length`, `jobs.length`). L'onglet est « chaud » (vert) s'il porte une action à faire : À livrer si `mine.length > 0`, Mes jobs si `readyJobCount > 0` (nouveau champ de `CompanionTasks`). Onglet initial : À attribuer. Les trois volets sont tous rendus, masqués par `v-show` (les `data-testid` existants restent valables). « En attente d'approbation » (`awaitingApproval`) s'affiche dans À livrer, après les lignes à livrer, avec son étiquette ambre et sans bouton Livrer.
R11 **Groupes repliables** : l'en-tête de chaque groupe d'un volet est un bouton (compteur visible replié) ; l'état replié est mémorisé (`companionCollapsedSections`, clés `free:<groupe>`, `mine:<groupe>`, `jobs:<groupe>`). Les anciennes clés `free`, `mine`, `free:buy`, `free:build`, `mine:buy`, `mine:build` stockées sont ignorées sans erreur. Le repliage des trois volets entiers disparaît avec les onglets (décision de l'issue : onglets à la place des sections empilées).
R12 **Lignes** : bande de couleur du groupe, icône du type (`getTypeIconUrl(typeId, 64)`), cadre violet sur une ligne `copy`. Libellé d'activité traduit (`companion.activities.<groupe>`, FR et EN) à la place de la valeur anglaise brute (« Reaction · 40 runs » → « Réaction · 40 runs »). Activité inconnue ou runs inconnus : « inconnu » (`data-unknown="true"`), jamais 0. Noms (projet, personnage, structure, type) tronqués avec infobulle.
R13 **Barre** : même en-tête (produit, nom tronqué, « direct », jauge) puis compteurs segmentés : À attribuer (+ mini-barre par groupe, largeur proportionnelle au nombre de lignes libres du groupe), À livrer, Mes jobs (+ « N ✓ » si `readyJobCount > 0`), À revoir (si `toReview !== null`), puis l'alerte prioritaire avec son bouton, puis le bouton Colonne. Tient dans 1024×72 sans défilement horizontal.
R14 **Colonne** : en-tête, chaîne d'avancement (une barre fine par groupe R4, même couleur que les listes, infobulle = libellé + `delivered/total`), alertes, onglets, volet défilant, pied. Tient dans 360×640 sans défilement horizontal ; seul le volet défile.
R15 **Inchangé** : libellés et actions (claim, Tout livrer, Libérer, statuts de job, « Sortie : … »), copie du nom de blueprint, bouton marché en jeu, bascule barre/colonne, ordre de priorité des alertes, ligne « lieu de sortie inconnu » de la colonne (pas dans la barre), étiquette « en attente de composants », section « à faire » du pied réservée aux admins (`toReview`, `joinRequests` non null).

## Exemples chiffrés (tests rouges)
| Entrée | Sortie |
|---|---|
| lignes : matériau, copy, reaction, `isJob` sans activité, `activityType: 'salvage'` | groupes dans l'ordre copy, reaction, material, unknown (les deux lignes inconnues dans `unknown`) |
| libres : 9 lignes (1 copy, 2 reaction, 1 manufacturing, 5 material) | 6 lignes affichées dans l'ordre copy, reaction ×2, manufacturing, material ×2 ; reste 3 |
| reaction : 4 lignes dont 1 `isFulfilled` ; manufacturing : 0 ligne | `{reaction, 1, 4, 25}`, aucune entrée manufacturing |
| libres matériaux : 1 000 × 5,5 et 80 × `null` | `freeValue = { total: 5 500, unknownLines: 1 }` ; toutes `null` → total inconnu |
| job `active`, `endDate` = now + 90 min | `running`, `remainingMs` = 5 400 000 ; affiché `01:30` |
| job `active`, `endDate` = now − 1 min | `ready`, `remainingMs` 0 |
| job `paused`, `endDate` = now + 50 h | `paused`, affiché `2 j 02 h` |
| job `active`, `endDate` null | `running`, `remainingMs` null, affiché « inconnu » |
| 14 lignes livrées sur 22 | pied « 14 / 22 » |

## Cas limites
Projet sans produit (`product` null : pas d'image, nom seul) ; produit multiple (`otherCount` dans l'infobulle) ; `bomPercent` > 100 ou < 0 (tracé borné, texte brut) ; activité inconnue seule dans la liste ; liste vide d'un volet (message existant `emptyFree`, `emptyMine`, `emptyJobs`) ; image CCP en erreur (`onImageError`, repli neutre) ; nom de 80 caractères (troncature + infobulle) ; stockage refusé (état replié non mémorisé, aucune erreur).

## Accès et fraîcheur
Aucun changement : mêmes données que le store compagnon, mêmes actions (`claim`, `release`, `deliver`, `open-bom`, `toggle-size`). Le presse-papier n'est touché que sur clic.

## Tests existants contredits (mis à jour en phase rouge)
- `groupCompanionSplit.spec.ts` : le découpage « À acheter / À fabriquer » et les compteurs de barre buy/build deviennent le regroupement par activité (R2, R11, R13) ; les cas de repliage et de stockage sont conservés avec les nouvelles clés.
- `groupCompanion.spec.ts` : plafond de 6 par sous-groupe → 6 au total (R3) ; section « Projet » et libellé « BOM N % » remplacés (R7) ; `companionTasksOf` gagne `now`, `product`, `activityProgress`, `deliveredLines`, `totalLines`, `freeValue`, `readyJobCount`.
- `groupCompanionWaiting.spec.ts`, `groupCompanionBlueprintCopy.spec.ts` : sélecteurs de sous-groupe buy/build remplacés par ceux des groupes d'activité.

## Hors périmètre
#1160, #1161, #1162, #1163 ; barre de progression des jobs en cours (demande une date de début absente de `JobMatch`, à traiter avec un champ API dans une issue à part) ; changement de `COMPANION_WINDOW_SIZES` (la barre y est à 1240 de large, la maquette dessine 1024) ; aucun réglage retiré.

## Décisions (mode nuit, sur recommandation)
1. Avancement par nombre de lignes, pas par quantité. 2. Compte à rebours sans secondes. 3. Barre de progression d'un job en cours reportée (date de début absente). 4. Repliage conservé au niveau des groupes d'activité. 5. Halo existant conservé. 6. `now` ajouté à `CompanionTasks` plutôt qu'en prop du composant.

## Glossaire
Ajout : **Groupe d'activité** (Activity group).
