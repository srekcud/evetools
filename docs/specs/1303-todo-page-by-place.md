# #1303 — To do page grouped by place with multibuy

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`actsHtml`, `todoFull`, `grouped`, `multibuy`). Front seul : la source est `GET /industry/place-actions` (#1300), la ventilation de la transaction du wallet est faite par la synchro (#1302). Aucune route API, aucune migration, aucun Mercure (aucune action asynchrone : le multibuy est une copie dans le presse-papiers).

## Contexte

Le module Industry n'a aucune page qui range les actions de tous les projets par lieu. L'Atelier affiche une prochaine action par projet, sans lieu. Le prototype propose une page « À faire » (une seule colonne, un bloc par lieu, lieu du personnage en tête, « Partout » en dernier, bouton multibuy dans le groupe du marché) et une colonne de projet qui en reprend les groupes (#1307).

## Règles

R1. **Route et accès.** Page `IndustryTodo.vue`, route `industry-todo` (`/industry/todo`, `requiresAuth`), avec le `MainLayout` et `IndustryModuleTabs`. `IndustryModulePage` gagne `todo`. L'onglet « À faire » (fr) / « To do » (en) se place après l'Atelier ; il ne porte pas de compteur (le compteur demanderait un appel par page du module, voir Décisions).

R2. **Chargement.** `useGroupPlaceActionsStore().fetchAll()` au montage, puis à chaque changement de `syncStore.getSyncProgress('industry-project')` (même déclencheur que l'Atelier). Chargement : indicateur ; erreur du store : `ErrorBanner` ; aucune action : message « Rien à faire » dans la zone de la page ; jamais de liste vide silencieuse.

R3. **Groupes de lieu** (fonction pure `groupActionsByPlace`, ordre du serveur conservé, jamais retrié). Clé de groupe : `station:<locationId>`, `anywhere`, `unknown:<unknownReason>`. Deux actions de même clé appartiennent au même groupe même si elles ne sont pas consécutives ; les groupes se rangent dans l'ordre de leur première action. Un groupe porte son lieu et `here` = `true` si au moins une de ses actions a `here === true`, sinon `false` si au moins une a `false`, sinon `null`.

R4. **En-tête de groupe.** Station : nom de la station, système en petit (omis si `solarSystemName` est `null`). `anywhere` : « Partout » / « Anywhere ». `unknown` : libellé par raison (`market_not_chosen` « Marché non choisi », `market_structure_unset` « Structure de marché non réglée », `station_unresolved` « Station de l'étape non résolue ») ; une raison inconnue du front affiche « Lieu inconnu » et la raison brute en petit. Badge « vous y êtes » (palette `ok` du prototype) quand `here === true` ; rien quand `false` ou `null`.

R5. **Ligne d'action.** Verbe par code : `launch_job` Lancer, `source_material` Acheter, `review_contributions` Vérifier, `review_join_requests` Accepter (en : Launch, Buy, Review, Accept). Icône du type (`useEveImages`, `typeId` `null` : pas d'icône), nom du type (`typeName` `null` : le verbe seul), sous-texte : `launch_job` « {n} runs à lancer » (n `null` : « runs à définir »), `source_material` « {n} à acheter », `review_contributions` « {n} contribution(s) à relire », `review_join_requests` « {n} demande(s) d'adhésion », sous-texte vide si `quantity` est `null`, jamais `0`. Étiquette du projet (`projectName`, `null` : « Projet sans nom »). Un clic ou Entrée ouvre le projet (`industry-project`, `params.id` = `projectId`) ; la ligne est un lien (`router-link`), pas un bouton qui prétend exécuter l'action.

R6. **Multibuy par marché** (fonction pure `placeMultibuy(group)`). Seuls les groupes `station` ont un multibuy, calculé sur leurs actions `source_material` dont `typeName` et `quantity` ne sont pas `null` et `quantity > 0`. Les lignes de même `typeId` (ou, à défaut de `typeId`, de même `typeName`) s'additionnent en une ligne `{typeName, quantity}`, dans l'ordre de première apparition. Résultat : `lines`, `lineCount` (nombre d'actions retenues), `projectCount` (projets distincts de ces actions), `skippedCount` (actions `source_material` du groupe écartées). `placeMultibuy` renvoie `null` quand `lineCount < 2` (seuil du prototype : un seul achat ne se « groupe » pas). Les groupes `anywhere` et `unknown` n'ont jamais de multibuy (pas de marché connu).

R7. **Bouton multibuy.** Dans l'en-tête du groupe : « Multibuy · {lineCount} lignes, {projectCount} projets » (singulier à 1 projet). Clic : `navigator.clipboard.writeText(multibuyText(lines))` (`utils/multibuy.ts` inchangé), puis le libellé devient « Copié. La synchro du wallet rattachera la transaction aux projets quand elle le pourra » (en : « Copied. The wallet sync will link the transaction to the projects when it can »), affiché sous le bouton pendant 2 s. Échec de la copie : message d'erreur discret dans le groupe, jamais « Copié ». `skippedCount > 0` : une phrase « {n} ligne(s) sans nom ou sans quantité non incluses ». Aucun appel API : le rattachement et la ventilation de la transaction restent ceux de la synchro du wallet (#1302).

R8. **Atelier inchangé.** `GET /industry/actions`, `IndustryWorkshop` et son compteur de navigation ne changent pas ; leur retrait n'est pas décidé ici.

R9. **Glossaire et i18n.** Clés `industryTodo.*` et `industryModule.tabs.todo` en fr et en (mêmes clés). Glossaire : « Page À faire » et « Multibuy par marché ».

## Exemples (tests rouges)

Actions (ordre serveur) : A1 `launch_job` Azbel (here true), A2 `source_material` Tritanium 400 Jita 4-4 (projet P1, here false), A3 `source_material` Tritanium 120 Jita 4-4 (projet P2), A4 `source_material` Zydrine 50 Jita 4-4 (P1), A5 `source_material` Isogen 10 `unknown/market_not_chosen`, A6 `review_join_requests` 2 `anywhere`.
- Groupes : Azbel (here), Jita 4-4, Marché non choisi, Partout. A2, A3, A4 dans Jita 4-4 même si A2 et A4 ne se suivent pas.
- Multibuy de Jita 4-4 : lignes `Tritanium 520`, `Zydrine 50` ; `lineCount` 3, `projectCount` 2 ; texte copié `Tritanium\t520\nZydrine\t50`. Libellé « Multibuy · 3 lignes, 2 projets ».
- Azbel (une action), Marché non choisi (une action `unknown`), Partout : aucun bouton multibuy.
- Une action `source_material` Jita sans `typeName` : écartée, `skippedCount` 1, le bouton reste si 2 autres lignes subsistent.
- Une seule action d'achat à Jita : pas de bouton.
- Une action sans position lisible (`here` null partout) : aucun badge « vous y êtes ».
- `typeName` null sur une ligne : verbe seul ; `quantity` null : pas de sous-texte chiffré.
- Aucune action : message « Rien à faire », pas de groupe. Erreur du store : bannière d'erreur.
- Clic sur une ligne : navigation vers `/industry/projects/<projectId>`.
- Onglet : six pages dans l'ordre Atelier, À faire, Projets, Analyse, Production, Revenus.
- Copie refusée par le navigateur : pas de « Copié », message d'erreur.

## Cas limites

Deux stations de même nom : séparées par `locationId`. Un nom de station très long : tronqué dans l'en-tête, titre complet en infobulle. Plusieurs personnages dockés : `here` vient déjà du serveur.

## Hors périmètre

Colonne « À faire » de la page projet (#1307), compagnon (#1304), prise de job, transport, virements, statut d'une action « faite », multibuy qui enregistre une répartition avant l'achat, ouverture du multibuy dans la fenêtre du jeu, retrait de l'Atelier ou de `GET /industry/actions`.

## Décisions

D1 : onglet du module sans compteur (le prototype en affiche un ; un compteur partout coûterait un appel `/location/` par personnage à chaque page du module, risque 420 relevé en revue de #1300) ; écart noté dans « À relire à la fin ». D2 : le multibuy copie le texte du marché, la ventilation reste celle de la synchro du wallet (#1302), l'écran ne la déclare pas. D3 : ligne d'action = lien vers le projet (le clic du prototype « fait » l'action, ce qui n'a pas de sens pour un achat ou un job lancés en jeu). D4 : seuil du multibuy à 2 lignes comme le prototype. D5 : Atelier conservé.

D6 (revue) : le message de copie ne promet pas la ventilation (la synchro de #1302 la fait seulement si les restants couvrent la transaction et sans ambiguïté de ligne) ; un lieu `station` sans nom affiche « Station sans nom ».
