# #335 reliquat : tableau des projets de l'Atelier (équipe, phase, profit)

Suite de `335-workshop-action-queue.md` (PR #401). Maquette `industry-project-v3.html#p=1` (notes 2, 3, 13). Circuit:m, niveau 3 (code Industry). Spec validée en autonomie (mode nuit).

Les exclusions de la première spec qui restaient faute de donnée sont reprises ici : avatars des membres, phase du cycle de vie avec compteur, profit par projet, liens d'en-tête. Ce qui reste hors périmètre est listé en bas.

## API (R1 à R5)
- **R1** `GET /industry/workshop-projects`, ressource `IndustryWorkshopProjectResource` (collection sans pagination, `security: is_granted('ROLE_USER')`, 401 sans utilisateur). Projets : ceux de `findAcceptedWithProjectsByUser` (même source que la liste des projets et que `/industry/actions`). Un participant `pending` ou un projet d'un autre utilisateur n'apparaît jamais.
- **R2 Équipe** : `members`, liste des participants `accepted` du projet, ordre `owner`, `admin`, `member` puis `joinedAt` croissant, chacun `{characterId: ?int, characterName: string, role}` (mêmes valeurs que `ProjectResourceMapper::memberToResource`, `PilotName::of`, `getEveCharacterId`). Les participants de tous les projets sont chargés en un nombre constant de requêtes (`loadUsersWithMainCharacter`), pas une requête par projet.
- **R3 Phases** : `phases`, celles de `ProjectCockpitCalculator` pour la personne connectée (même fabrique d'entrée que `IndustryActionsProvider`) : `{key, state, counter: {done, total}, blockedReason: {code, count}|null}`. Aucune règle copiée.
- **R4 Profit** : `profit: {amount: ?float, status}`. `amount` = `profitPart` de la personne connectée dans `ProjectDistributionService::calculateDistribution` (la part de l'apporteur ; pour un projet solo, le profit du projet). `status` : `awaiting_sales` (aucune vente, `amount` null), `provisional` (des ventes, reste à vendre), `final` (tout vendu), `unknown` (au moins une valeur de la cascade inconnue : `amount` null). Jamais 0 par défaut : une personne sans apport approuvé, un coût ou des frais inconnus donnent `unknown`.
- **R5** Une erreur du calculateur remonte (500 avec le projet fautif dans le message), comme pour `/industry/actions` : pas d'entrée ignorée en silence.

| Projet de la personne | `profit` |
|---|---|
| solo, aucune vente | `{amount: null, status: awaiting_sales}` |
| groupe, ventes partielles, part de la personne 1 200 000 | `{amount: 1200000, status: provisional}` |
| solo, tout vendu, profit 24 460 000 | `{amount: 24460000, status: final}` |
| ventes avec frais du projet inconnus | `{amount: null, status: unknown}` |
| groupe, la personne n'a aucun apport approuvé | `{amount: null, status: unknown}` |

## Front (R6 à R11)
- **R6** Store `stores/industry/workshopProjects.ts` (`fetchWorkshopProjects`, `byProjectId`, erreur explicite, jamais une liste vide silencieuse), rechargé sur l'événement Mercure `industry-project` comme les actions (aucun nouveau topic : aucune action asynchrone nouvelle).
- **R7 Colonne Équipe** : en plus de « Solo » / « Groupe · N » et du rôle, une pile d'avatars (`getCharacterPortraitUrl(characterId)`, `title` = nom du personnage, repli sur l'initiale si `characterId` est nul), au plus 5 avatars puis « +N ». Panneau de droite : la liste des membres avec leur rôle.
- **R8 Cycle de vie** : la colonne montre le libellé de la phase courante (première phase `current` ou `blocked`, clés `groupIndustry.cockpit.phases.*`) avec son compteur « done / total » ; une phase `blocked` colore son segment du mini rail en ambre et affiche sa raison (`groupIndustry.cockpit.blockedReasons.*`). Projet terminé : « Terminé », cinq segments verts. Le rail de 5 segments reste fondé sur le statut, la phase ambre remplace le segment de la phase bloquée.
- **R9 Colonne Profit** (alignée à droite, après le cycle de vie) : montant signé en vert (>= 0) ou rouge (< 0), `formatIsk`, avec le sous-texte par statut (`final` « final », `provisional` « ma part provisoire », `awaiting_sales` « aucune vente »). `unknown` ou `amount` nul : composant de valeur inconnue du module (violet pointillé, `UnknownValue`) avec la cause ; jamais « 0 ». Chargement ou erreur de l'endpoint : cellule inconnue et bandeau d'erreur, jamais de montant par défaut.
- **R10 Sous-ligne du produit** : `ME x · TE y · N participants` (ME et TE du premier item, `membersCount` ; « solo » si 1). Pas de nom de corporation (non exposé par la liste).
- **R11 En-tête de l'Atelier** : liens vers Compétences (déplacé de l'en-tête de la file d'actions), Blueprints (`/industry/production?tab=blueprints`), Revenus › Industrie (`pve-revenue`, `tab=industry`) et « Nouveau projet » (même intention de navigation que `IndustryDashboard`, `openCreateModal: true`). Carte « Prochaines fins de jobs » : chaque ligne ajoute l'heure de fin locale (`HH:mm`) avant « dans durée ».
- Direction HUD EVE, mobile 375 px sans débordement, noms longs tronqués (`truncate` + `title`), clés i18n FR et EN, aucun commentaire de code.

## Cas limites
- Projet sans participant `accepted` autre que la personne : `members` contient la personne seule (solo).
- `characterId` nul : avatar par initiale, jamais d'URL de portrait avec 0.
- Une phase `blocked` et aucune `current` : la phase bloquée est la phase affichée.

## Accès et fraîcheur
- Seules les lignes de la personne connectée ; aucun identifiant en entrée ; calcul à chaque lecture, rien de persisté. Les noms et personnages des co-participants sont déjà visibles de tout participant accepté (`/industry/projects/{id}/participants`).

## Hors périmètre (reste de #335, à décider)
- Cartes KPI de la maquette (Jobs en cours, Slots libres, Capital engagé, Profit attendu) : `IndustryDashboard` affiche déjà projets actifs, slots et santé du pipeline sur la même page ; le remplacer est une décision de Sylvain (aucun retrait sans accord), et Capital engagé et Profit attendu demandent un agrégat de cascade par projet.
- Profit prévisionnel avant la première vente (prévision Jita, `ProjectSellForecast` par projet : un appel marché par ligne).
- Lien « Hangars corpo » (aucun écran), puce de fraîcheur « sync ESI il y a N min », nom de corporation dans la sous-ligne, sévérité et impact en ISK dans la file d'actions, alertes prix, ordres sous-cotés, santé des structures (autres issues).
