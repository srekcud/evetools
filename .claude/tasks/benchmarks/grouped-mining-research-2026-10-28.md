# Minage groupé : recherche et découpage en issues (2026-10-28)

Lecture seule. Aucune issue créée. Sources externes = données non fiables, citées par URL ; code evetools cité en `fichier:ligne` (main @ ec269dd).

Note de périmètre : le brief demandait `.claude/tasks/grouped-mining-research-2026-10-28.md` ; le fichier est rangé dans `.claude/tasks/benchmarks/`, seul dossier où l'écriture est autorisée pour ce type de recherche.

Anonymisation : aucun pseudo d'auteur dans le texte. Deux URL de dépôts contiennent un identifiant GitHub : elles sont citées telles quelles parce que la source doit rester vérifiable.

---

## 0. Résumé

- Le seul outil de référence du minage en flotte est **EVE Ops** (`eve.wxmx.de`). Il prend un **instantané du ledger ESI** au début et à la fin de chaque opération, et la différence donne le minerai miné. Il regroupe main et alts, propose des invitations par lien et la visibilité corpo/alliance, et valorise en Jita buy compressé. Il gère aussi le booster en %, le fleet storage « sur la confiance » et un archivage. Aucune formule de répartition n'est publiée.
- evetools a déjà **l'essentiel des briques** : sync ledger paginée multi-personnages, valorisation brut/compressé/retraitement, modèle de groupe avec lien court, rôles et découverte corpo (Group Industry), Mercure, et les trois scopes (`read_character_mining`, `read_corporation_mining`, `read_fleet`).
- **Bloquant avant tout MVP** : evetools valorise le minerai compressé **100 fois trop bas**. C'est le constat P1-1 de l'audit du 2026-10-11, jamais passé en issue. CCP l'a confirmé : depuis 2021, le compressé a la même portion que le brut.
- **Estimateur d'épuisement d'un site** : je n'ai trouvé aucun outil vérifiable. Une page (evehaklabs) affiche des libellés « remaining volume / depletion / ETA », mais elle est inaccessible (erreur TLS). Depuis Catalyst (2025-11-18), le survey scanner est devenu un *Mining Surveyor* à overlay. **Rien ne confirme qu'on puisse encore copier-coller ses résultats.** Il faut le vérifier en jeu avant d'ouvrir l'issue.

---

## (a) Ce que fait réellement chaque source

### A1. « eveminingfleet » → EVE Ops (`https://eve.wxmx.de/`)

Je n'ai trouvé aucun site nommé exactement « eveminingfleet ». Le fil cité par Sylvain présente un outil intitulé « EVE Fleet Mining », dont l'interface s'appelle EVE Ops.

Sources : [fil forum 512000](https://forums.eveonline.com/t/new-eve-fleet-mining-ratting-tool/512000), [accueil](https://eve.wxmx.de/), [aide](https://eve.wxmx.de/help).

| Fonction | Ce que dit la source | URL |
|---|---|---|
| Création d'une opération | Nom, lieu de minage, infos, type d'accès, booster, destinataire du storage, pourcentages. Démarrage possible seulement si le créateur a un accès ESI et n'est pas déjà dans une autre opération. | /help |
| Invitations | Accès privé par lien, lien renouvelable pour invalider l'ancien. Accès corpo ou alliance : l'opération est listée sur l'accueil des joueurs concernés. Le chef ne voit que les joueurs qui ont rejoint. | /help |
| Main / alts | On se connecte avec le main et on ajoute les alts une fois ; ensuite, le login du main charge les alts. Vue par joueur et par personnage. | /help, fil 512000 |
| Quantités minées | « Beim Start wird ein Start-Stand gespeichert » (instantané au début), un instantané à la fin ou à la clôture anticipée d'un joueur, et « Die Differenz ist das geminte Material » (la différence est le minerai miné). | /help |
| Délai | « the Mining Ledger ESI is cached by EVE and can only update about every 600 seconds » | fil 512000 |
| Valeur | Jita 4-4 **buy du compressé** (« komprimierten Jita 4-4 Buy »). | /help, / |
| Reprocess | Vue des minéraux qu'on obtiendrait. Le rendement utilisé n'est pas publié (**inconnu**). | /help |
| Fleet storage | Chaque mineur choisit s'il verse son minerai au destinataire. « Fleet Storage bleibt deshalb bewusst auf Vertrauensbasis » : aucune lecture d'assets pour vérifier. | /help |
| Booster | Paiement convenu en pourcentage. Les postes à payer ou à rendre n'apparaissent qu'à la clôture, et chacun se marque « fait » individuellement. **Aucune formule publiée.** | /help |
| Archive | Une opération close reste consultable. Les résultats d'une opération privée ne sont visibles que des anciens participants. | /help |
| Scopes | « No asset access, mail access, contract access, market order access, or write access is requested. » | fil 512000 |
| Loot History (bêta, 2026-06-26) | Upload du fichier de log de flotte, croisé avec les instantanés du ledger : signale les écarts de plus de 15 % par minerai, avec une notification Discord. | fil 512000 |
| Avis joueurs | Un seul remerciement dans le fil, aucune critique. Fil fermé le 2026-09-24. | fil 512000 |

Licence et code : **inconnus**. Aucun lien vers un dépôt public.

### A2. Ore Table (`https://ore.cerlestes.de/`)

L'application est une SPA, donc WebFetch ne lit que le titre. J'ai lu les libellés et la logique dans le bundle JS public (`/assets/index-BgbxBGtM.js`, lecture seule). La licence affichée est **« All rights reserved. »** : on reprend des idées, jamais de code. Version : « Ore Table v6.2.2 », données de jeu « Catalyst (v23.02; 2025-11-18) ».

- **Unité de référence** : le m³, pas l'unité d'inventaire. Le texte du tutoriel : « this site is based on per-m³ contents rather than inventory units… your mining lasers aren't mining a certain number of units, but rather a constant amount of m³ ». Le volume de référence se règle (« Amount », 1 m³ par défaut ; « Minerals in %d m³ ore »).
- **Colonnes de la table des minerais** : une colonne par minéral (quantité contenue), puis :
  - « Refined Value » (« market value for the minerals contained in the raw ore ») ;
  - « Raw Ore Price », « Compressed Ore Price » et « Batch Compressed Ore Price » (prix d'une unité, « Value is not per m³ ») ;
  - « Found in » (régions ou factions).
- **Calcul des minéraux** : `minéraux par m³ = (1 + 0,05 × rang de variante) × matériaux par batch / (taille du batch × volume)`. Le rang de variante vaut 0 pour la base, puis +5 % et +10 %.
- **Comparaison des prix au retraitement** : le diviseur vaut `volume` pour le compressé et `volume × 100` pour le batch compressed. Une unité compressée équivaut donc à une unité brute, et une unité batch compressed à 100 unités brutes. C'est cohérent avec la règle CCP de A6.
- **Réglages** : rendement de retraitement en % ou en fraction (synchronisable entre tables), rendement de décompression du gaz (0,9 par défaut), région du marché (The Forge, Domain, Sinq Laison, Heimatar par défaut) et type de prix (Buy/Sell, Max, 98e ou 90e percentile pondéré par volume). D'autres options affichent ou masquent les colonnes brut, compressé, batch, prix au m³ et l'écart sell/buy.
- **Filtres** : Show High-Security / Low-Security / Null-Security, Triglavian, A0-Stars, et « Sov-Hub upgraded Null-Security ».
- **Autres outils** : Refining Calculator, Reverse Refining Calculator (« amount of ore or ice required to produce a desired amount of refinery products »), Market History et Market Compare. Le « Yield Calculator » (rendement dérivé des compétences et de l'équipement) est annoncé « coming in a future update ».
- **Prix** : l'outil lit ses propres fichiers JSON (`/data/overview/…`, `/data/history/…`). La source amont est **inconnue**.
- **Avis joueurs** : un fil le qualifie de « brilliant resource ». L'auteur y explique que les chiffres suivent le champ « Amount » et le rendement réglé ([fil 263412](https://forums.eveonline.com/t/help-for-a-newbro/263412)).

### A3. Estimateur d'épuisement d'une belt ou d'une anomalie

**Je ne l'ai pas trouvé de façon vérifiable.** Recherches tentées : « belt depletion », « survey scanner paste », « anomaly time remaining », « ore site lifetime », « mining belt timer », en mode standard et étendu.

- **evehaklabs, Survey Scanner** (`https://www.evehaklabs.cloud/tools/survey-scanner`) : seul un extrait de moteur de recherche est disponible (« v0.9.3, May 2026 », libellés « remaining volume », « estimated remaining value », « depletion », « ETA »). La page renvoie une erreur TLS à WebFetch et à curl. Format d'entrée, méthode de calcul et licence : **inconnus**. C'est probablement le site visé par Sylvain, sans certitude.
- **EVE-HUB Mining** ([page](https://www.eve-hub.com/mining)) : la page confirme seulement « paste survey scanner output » → « organised, readable breakdown of the belt », plus un scouting des systèmes voisins. Un snippet de recherche parle de « depletion tracking », mais la page lue ne le montre pas : **non vérifié**. Licence inconnue, outil « fan-made ».
- **skŸlizer** ([GitHub](https://github.com/chrRtg/eve-skylizer)) : Apache-2.0, archivé le 2025-08-17. Il traite les scans de lune, le d-scan et le probe scanner, mais pas le survey scanner, et ne fait aucun calcul d'épuisement.

**Ce qui a changé côté jeu** ([notes Catalyst, CCP](https://www.eveonline.com/news/view/catalyst-expansion-notes), 2025-11-18) :
- un *Mining Surveyor* intégré aux vaisseaux ORE affiche un overlay coloré sur les astéroïdes ;
- le *Mining Survey Chipset* (ex-survey scanner, module passif) révèle « the number of units of ore available » ainsi qu'une valeur totale ;
- « All the current scan data will be retained within the survey scan result window until player re-scan » : la fenêtre de résultats existe donc encore ;
- les bursts Foreman partagent ces données à la flotte ;
- les cycles sont **4 fois plus rapides** et l'arrondi est stochastique ;
- les **coups critiques** donnent un bonus de rendement « without reducing the remaining ore in the asteroids » ;
- le *mining residue* existe toujours.

Côté joueurs ([fil 516885, août 2026](https://forums.eveonline.com/t/survey-scanners/516885)), on regrette l'ancien affichage du volume par roche, qui permettait de totaliser une belt, et l'information est désormais limitée aux roches verrouillées. **On ne sait pas si la fenêtre de résultats se copie dans le presse-papiers** : je n'ai trouvé ni source CCP ni source joueur. À vérifier en jeu (question Q5).

### A4. Autres outils

| Outil | Ce qu'il fait (sourcé) | Licence | URL |
|---|---|---|---|
| EVE Miner Hub | Agrège le ledger ESI de tous les personnages : dashboard, ISK/h de flotte détecté à partir des ticks de mise à jour, classement « What's most profitable to mine », ventilation par système. Retraitement estimé à 72,36 % (station NPC), 87,62 % (Athanor T2) et 90,63 % (Tatara T2). Rafraîchissement toutes les 5 min pour les mineurs actifs, 10 à 15 min pour les autres. Aucune répartition de gains. | inconnue | [fil 509265](https://forums.eveonline.com/t/eve-miner-hub-a-complete-overview-of-the-mining-tracker-for-eve-online/509265) |
| EVE Fleet Manager v19 (Google Sheets) | Ledger, transactions wallet et ordres pour 1 à 8 personnages. Top 5 des gains, ISK/jour sur 14 jours, objectif de minage pour le prochain PLEX. Ni répartition ni base de valorisation décrite. | inconnue | [fil 509080](https://forums.eveonline.com/t/eve-fleet-manager-v19-0-jita-trading-calculator-free-google-sheets-tools/509080) |
| Mining Manager (plugin SeAT) | Observers corpo + ledger perso agrégés par jour, taxe par catégorie de minerai (R4 à R64, ice, gaz…), « événements » qui modulent la taxe (de 0 à ×2 sur une fenêtre de temps), suivi des extractions lunaires, détection de vol, prix via Fuzzwork, Janice ou SeAT. | **GPL-2.0** (badge) : idée seulement | [GitHub](https://github.com/MattFalahe/Mining-Manager) |
| seat-corp-mining-tax | Taxe de minage corpo dans SeAT. | **GPL-3.0** (API GitHub) : idée seulement | [Packagist](https://root.packagist.org/packages/pytonicis/seat-corp-mining-tax) |
| aa-miningtaxes (Alliance Auth) | Taxe de minage, avec option « taxer seulement les lunes corpo via les observers » (snippet). Page PyPI illisible. | inconnue | [PyPI](https://pypi.org/project/aa-miningtaxes) |
| EVE University : worksheet d'opération | Pointage entrée et sortie (deux fois au plus), temps docké déduit, « Weight Time » pondéré par type de vaisseau, alts regroupés par multiplicateur. Paiement en ISK, en minéraux ou en minerai ; frais (fret) ajoutés à part. Page archivée, « no longer current ». | wiki | [EVE Uni](https://wiki.eveuniversity.org/Archive:Mining_operations_worksheet) |
| Pratiques joueurs | Répartition à partir du ledger in-game, ou au temps pondéré (Venture 0,75, barge 1, Orca du FC 2) sur la valeur **retraitée** par un membre aux compétences maximales. | — | [fil 343597](https://forums.eveonline.com/t/mining-fleet-payouts/343597) (2021) |
| Janice | Appraisal avec API à clé (snippet) ; aucune fonction de répartition trouvée. | inconnue | [doc aa-eveprices](https://aa-eveprices.readthedocs.io/en/latest/_sources/admin/janice.md.txt) |
| EFMD, Mining Buddy | **Introuvables** sous ces noms (deux recherches). | — | — |

### A5. ESI : vérifié dans l'OpenAPI officiel

Source : `https://esi.evetech.net/meta/openapi.json` (version `2025-08-26`), extraits `x-cache-age`, `x-required-roles` et `security`. Au passage, `https://esi.evetech.net/latest/swagger.json` renvoie 404.

| Endpoint | Scope | Rôle corpo | Cache | Paginé | Champs |
|---|---|---|---|---|---|
| `GET /characters/{id}/mining` | `esi-industry.read_character_mining.v1` | — | **600 s** | oui (`X-Pages`) | `date` (date), `type_id`, `solar_system_id`, `quantity` ; « past 30 days » |
| `GET /corporation/{id}/mining/observers` | `esi-industry.read_corporation_mining.v1` | **Accountant** | 3600 s | oui | `observer_id`, `observer_type`, `last_updated` |
| `GET /corporation/{id}/mining/observers/{observer_id}` | idem | **Accountant** | 3600 s | oui | `character_id`, `recorded_corporation_id`, `type_id`, `quantity`, `last_updated` (date) |
| `GET /corporation/{id}/mining/extractions` | idem | **Station_Manager** | 1800 s | oui | `structure_id`, `moon_id`, `extraction_start_time`, `chunk_arrival_time`, `natural_decay_time` |
| `GET /characters/{id}/fleet` | `esi-fleets.read_fleet.v1` | — | 60 s | non | `fleet_id`, `fleet_boss_id`, `role`, `wing_id`, `squad_id` |
| `GET /fleets/{fleet_id}/members` | `esi-fleets.read_fleet.v1` | — | **5 s** | non | `character_id`, `join_time`, `ship_type_id`, `solar_system_id`, `role`, `takes_fleet_warp`… |

Conséquences :
- Le ledger perso est **agrégé par jour** (pas d'heure) : seule la différence entre deux instantanés isole une opération. Le fuseau du champ `date` n'est pas documenté (UTC probable, **non confirmé**).
- Les 600 s de cache sont confirmés par l'OpenAPI et par EVE Ops : l'instantané de fin n'est fiable qu'après l'`Expires` qui suit la fin du minage.
- `/fleets/{fleet_id}/members` réservé au boss : **non confirmé** par l'OpenAPI, qui ne documente que 200 et `default`. un extrait de recherche attribué au wiki EVE University indique que seuls le boss et le FC voient toute la flotte ([wiki](https://wiki.eveuniversity.org/Fleet_interface), page non lue directement). À traiter comme « boss probablement requis ».
- Le ledger d'observer ne couvre que les champs observés par une structure (lunes) et exige un rôle corpo : c'est un autre cas d'usage (taxe lunaire), pas le minage en flotte d'anomalies.

### A6. Règles du jeu utilisées

| Règle | Statut | Sources |
|---|---|---|
| Compressé et brut ont la même portion (100) et donnent les mêmes minéraux par batch ; le batch compressed se retraite à l'unité. | **Confirmée** (CCP + EVE Uni + Ore Table) | [CCP, « From Extraction to Production »](https://www.eveonline.com/news/view/from-extraction-to-production) : « Compressed types will now have the same portion size as their uncompressed counterparts » ; [EVE Uni Reprocessing](https://wiki.eveuniversity.org/Reprocessing) |
| Compression en station : 90 % d'efficacité pour le minerai et le gaz, 79 % pour la glace. | Confirmée (CCP) | même article CCP |
| Efficacité de la compression en espace | **Non confirmée** : l'article dit seulement que la station est « less efficient » | même article CCP |
| Rendement en station NPC : `base × (1+0,03·Reprocessing) × (1+0,02·Efficiency) × (1+0,02·Ore Processing) × (1+implant)`, base de 50 % au mieux | Confirmée par deux sources non CCP | [EVE Uni](https://wiki.eveuniversity.org/Reprocessing) ; EVE Miner Hub : 72,36 % = 50 × 1,15 × 1,1 × 1,1 × 1,04, cohérent |
| Rendement en structure : `(50+Rm) × (1+Sec) × (1+Sm) × skills × (1+Im)`, avec Rm 0/1/3, Sec 0/0,06/0,12 (rig seulement), Sm 0/0,02 (Athanor)/0,055 (Tatara) | Confirmée par deux sources non CCP | EVE Uni ; EVE Miner Hub (87,62 % Athanor, 90,63 % Tatara, cohérents) ; [fil forum 38090](https://forums.eveonline.com/t/what-is-the-new-ore-refining-formula-for-athanor-and-tatara-citadels/38090) |
| Taxe de retraitement NPC de 5 % à standing nul | Non confirmée (une seule source) | EVE Uni |
| Coups critiques sans déplétion de l'astéroïde ; *residue* qui détruit du minerai | Confirmée (CCP) | notes Catalyst |

---

## (b) Formules

### B1. Épuisement d'un site à partir de 2 scans ou plus

Entrée : des scans horodatés `(t_k, Q_k)`, où `Q_k` est la quantité restante, en unités ou en m³ (`unités × volume du type`, SDE).

- **Deux scans** : débit `r = (Q_1 − Q_2) / (t_2 − t_1)`, puis `ETA = t_2 + Q_2 / r`. Si `r ≤ 0`, l'ETA est **inconnue** (pas « jamais »).
- **Trois scans ou plus** : régression linéaire par moindres carrés de `Q` en fonction de `t`, de pente `−r`. L'ETA est le temps où la droite atteint 0. On affiche aussi la dispersion : un débit qui change (mineurs qui arrivent ou partent) rend la droite fausse, et ce n'est pas un bruit à lisser.
- **Granularité** : une ETA par type de minerai, sur la somme des roches du type. L'ETA du site est la plus tardive. L'ETA « du minerai qui rapporte » est celle du type à plus forte valeur au m³.
- **Appariement entre scans** : faute d'identifiant de roche connu, on somme par type. Une roche hors portée à l'un des scans fausse le delta, d'où la règle « même position, même portée ».
- **Débit du ledger** (spécifique à evetools, voir E1) : `r_ledger = Σ quantités minées par les participants / durée`, tiré des instantanés. Il mesure ce que les mineurs reçoivent, pas ce que perd la roche : les critiques donnent du minerai sans vider la roche, le residue en détruit sans le donner. Le débit mesuré par scans est la vérité de la déplétion ; le débit du ledger sert de repli avec **un seul** scan, signalé comme approximatif.

### B2. Valeur d'une unité de minerai brut

Notations : `p(x)` est le prix de marché retenu pour `x` (Jita buy, par exemple), `portion = 100`, `m_j` la quantité de minéral `j` par portion (SDE `invTypeMaterials`) et `Y` le rendement de retraitement.

- Vendu brut : `V_brut = p(brut)`.
- Vendu compressé (nouveau type) : `V_comp = p(compressé) × e`, avec `e = 0,90` en station (CCP), et `e` en espace non confirmé (à saisir). **Pas de division par 100.**
- Vendu batch compressed (ancien type) : `V_batch = p(batch) / 100`.
- Retraité : `V_retr = Σ_j (m_j × Y × p(j)) / portion × (1 − taxe de retraitement)`.
- ISK par m³ : `V / volume(brut)` (volume SDE). C'est la base de comparaison de l'Ore Table.
- « Meilleure valeur » actuelle d'evetools : `max(V_comp, V_retr, valeurs structure)` (`MiningBestValueCalculator.php:163-186`). Pour **répartir des gains**, il faut une base unique et annoncée, pas un `max` qui change d'un type à l'autre (voir Q4).

### B3. Répartition des gains d'une opération

Notations : `q_{i,t}` est la quantité du type `t` minée par le participant `i` (somme de ses personnages, deltas d'instantanés), `V_t` la valeur unitaire selon la base choisie, `G = Σ_i Σ_t q_{i,t} × V_t` la valeur brute de l'opération, `b` la part du booster, `c` la part corpo et `f` des frais fixes (fret).

1. **Au prorata de la valeur** (EVE Ops, ledger in-game du fil 343597) :
   `part_i = (G × (1 − b − c) − f) × (Σ_t q_{i,t} V_t) / G` ; le booster reçoit `G × b`.
2. **Au temps pondéré** (worksheet EVE Uni, fil 343597) :
   `part_i = (G × (1 − b − c) − f) × w_i T_i / Σ_j w_j T_j`, où `T_i` est le temps de présence (déclaré, ou `join_time` ESI si le boss l'autorise) et `w_i` un poids par type de vaisseau.
3. **Fleet storage** : le minerai versé au destinataire crée une dette `dette_{dest→i} = part_i − valeur de ce que i a gardé`. C'est un solde par paire, comme les postes « à rendre » d'EVE Ops.

Une valeur `V_t` manquante rend `G` et toutes les parts **inconnues**, avec la liste des types sans prix (règle du glossaire « Coût inconnu »).

---

## (c) Ce qu'evetools a déjà, ce qui manque

### Déjà là

| Brique | Où |
|---|---|
| Ledger perso multi-personnages, paginé, upsert par (perso, date, type, système), Mercure `mining` | `src/Service/Sync/MiningSyncService.php:106-141`, `:120` (`getPaginated`), `:227-288` ; clé unique `src/Entity/MiningEntry.php:14` |
| Sync planifiée du ledger toutes les heures | `src/Scheduler/SyncScheduler.php:68-70` |
| Lecture paginée avec `Expires`, utile pour savoir quand l'instantané de fin devient fiable | `src/Service/ESI/EsiClient.php:216` (`getPaginatedWithExpiry`) |
| Regroupement main / alts | natif : un `User` porte ses `Character` (`MiningSyncService.php:106`) ; EVE Ops doit le faire saisir |
| Valorisation brut, compressé, retraitement (Jita et structure), taxe d'export | `src/Service/OreValueService.php:41-180` |
| Rendement réglable par l'utilisateur | front seulement, en `localStorage` (`frontend/src/components/ledger/MiningTab.vue:117-124`), transmis en paramètre (`frontend/src/stores/ledger.ts:208`) |
| Usage d'une entrée (vendu, projet corpo, industrie) | `src/Entity/MiningEntry.php:19-22` |
| Modèle de groupe : lien court, rôles Owner/Admin/Member, statuts de membre, découverte corpo, `join/{shortLinkCode}`, distribution | `src/Entity/GroupIndustryProject.php:39-40,103-106`, `src/Enum/GroupMemberRole.php`, `src/Enum/GroupMemberStatus.php`, `src/Repository/GroupIndustryProjectRepository.php:28-37`, `src/ApiResource/GroupIndustry/GroupIndustryProjectResource.php:91`, route front `/join/:code` (`frontend/src/router/index.ts:125`), `src/Service/GroupIndustry/GroupIndustryDistributionService.php` |
| Vérification d'un container corpo par son nom (Group Industry) | `src/ApiResource/GroupIndustry/GroupContainerVerificationResource.php` : pourrait vérifier un fleet storage, ce qu'EVE Ops ne fait pas |
| Scopes `read_character_mining`, `read_corporation_mining`, `read_fleet` déjà demandés | `src/Service/ESI/AuthenticationService.php:40,41,53` ; #42 fermée en les gardant pour des fonctions futures (`.claude/tasks/todo.md:229`) |
| Parseur de collage d'items | `src/Service/ItemParserService.php:27,106-143` : le format du survey scan est inconnu, réutilisation non garantie |

### Manque ou bug (prérequis)

1. **Compressé valorisé 100 fois trop bas** : `OreValueService.php:15-16` (`COMPRESSION_RATIO = 100`), `:132` ; `MiningBestValueCalculator.php:174` (`/ 100`) ; `useMiningPricing.ts`. Règle confirmée en A6. C'est le constat P1-1 de l'audit (`.claude/tasks/audit-mining-pve-wallet-2026-10-11.md:13`). Aucune issue trouvée (recherches `compress`, `reprocess`, label `mining`). **Une répartition fondée sur le compressé serait fausse d'un facteur 100.**
2. **`?? 0.0` sur un prix de minerai** : `MiningBestValueCalculator.php:46,84,123` et repli `:182`. C'est le constat P2-5 de l'audit, toujours dans le code. #222 (fermée) traitait l'affichage, pas ce calcul.
3. **Rendement par défaut 0,78 non sourcé** : `OreValueService.php:19` (« 78% yield with skills »). Une station NPC plafonne à environ 69,6 % sans implant, 72,4 % avec (A6) : 78 % ne s'atteint qu'en structure.
4. **Rendement non persisté côté serveur** : `localStorage` seulement, donc impossible de fixer un rendement commun à une opération.
5. **Glossaire sans terme de minage** : constat P3-11 de l'audit ; `docs/glossary.md` n'a aucune section minage.
6. Rien sur l'opération (entité, instantanés, répartition), ni sur les observers, les extractions ou la flotte ESI : aucun appel trouvé (`grep observer|mining/extractions|/fleet` sur `src`, `frontend/src`).

---

## (d) Découpage en issues

Labels : type + module, parmi ceux du dépôt (`feature`, `bug`, `docs`, `mining`, `corp-industry`, `infra`…). Tailles S/M/L. Ordre : prérequis, MVP, suites.

### Prérequis

**P1. Le minerai compressé est valorisé 100 fois trop bas** (`bug`, `mining`, S)
- Constat : `compressedEquivalentPrice = prix compressé / 100` (`OreValueService.php:132`). Le meilleur prix structure fait de même (`MiningBestValueCalculator.php:174`).
- Preuve : CCP, « Compressed types will now have the same portion size » ; EVE Uni ; Ore Table compare le compressé avec un diviseur `volume` et le batch avec `volume × 100`.
- Impact : la colonne « Jita compressé » et le « meilleur prix » sont sous-évalués ; le compressé n'est jamais retenu ; toute répartition future est fausse.
- Test qui le prouverait : Veldspar brut avec un prix compressé de 1 000 donne `compressedEquivalentPrice == 1000.0` (aujourd'hui 10.0). Un type « Batch Compressed … » garde la division par 100.
- Hors périmètre : refonte des trois implémentations de la valeur (#38).

**P2. Valeur de minage : un prix manquant compte pour 0** (`bug`, `mining`, S)
- `MiningBestValueCalculator.php:46,84,123`. Total inconnu et liste des types sans prix, comme la règle « Coût inconnu ».
- Test : un type sans prix donne un total `null` et un `missingPriceTypeIds` non vide.

**P3. Glossaire : section Minage** (`docs`, `mining`, S)
- Termes proposés (FR / EN) :
  - **Opération de minage** / Mining operation : le groupe éphémère ; à ne pas appeler « flotte », qui est l'objet ESI.
  - **Instantané du ledger** / Ledger snapshot.
  - **Quantité minée d'une opération** / Operation mined quantity : différence des instantanés.
  - **Base de valorisation** / Valuation basis : brut, compressé ou retraité, avec son marché.
  - **Rendement de retraitement** / Reprocessing yield.
  - **Part** / Share.
  - **Part du booster** / Booster cut.
  - **Fleet storage** / Fleet storage.
  - **Scan de site** / Site scan.
  - **ETA d'épuisement** / Depletion ETA.

### MVP

**M1. Opération de minage : créer, rejoindre par lien court, découverte corpo** (`feature`, `mining`, M)
- Besoin : réunir les participants d'une session sans tableur.
- Comportement :
  - un pilote crée « Anomalie Spodumain, 20:00 EVE ». Il obtient un lien `/mining-op/join/ab12cd34ef` et choisit la visibilité « privée » ou « ma corpo » ;
  - un membre de la même corpo la voit dans « disponibles » ;
  - en rejoignant, il coche ceux de ses personnages qui participent (main et deux alts) ;
  - rôles Owner / Admin / Member, statuts pending / accepted / removed, sur le modèle de Group Industry.
- Fichiers : nouvelles entités `MiningOperation`, `MiningOperationMember` et `MiningOperationCharacter` ; ressources API Platform sous `/mining-operations` ; front avec une vue et une route de join.
- Hors périmètre : alliance, invitation en jeu (`write_fleet`, scope non demandé).
- Dépendances : P3, décision Q1.

**M2. Instantanés du ledger au début et à la fin, quantités par participant et par personnage** (`feature`, `mining`, L)
- Comportement :
  - au démarrage, sync à la demande de `/characters/{id}/mining` pour chaque personnage inscrit, et enregistrement de l'instantané (perso, date, type, système, quantité) ;
  - à la clôture, nouvel instantané **après** l'`Expires` ESI (au plus 600 s). En attendant, l'opération affiche « en attente du ledger, prêt à 21:42 » ;
  - quantité minée = fin − début, sommée sur les dates si l'opération passe minuit ;
  - progression via Mercure (`syncProgress`), une ligne par personnage.
- Exemple : un personnage avait 12 000 Spodumain à 20:00 et en a 47 500 à 21:30. L'opération lui compte 35 500.
- Cas limites :
  - personnage sans jeton ou jeton invalide : participant « inconnu », pas 0 ;
  - minage hors opération pendant la fenêtre : compté, limite connue et affichée ;
  - clôture anticipée d'un seul participant (EVE Ops le permet).
- Fichiers : `MiningSyncService` (extraire la lecture ESI d'un personnage), `EsiClient::getPaginatedWithExpiry`, nouveau handler Messenger pour l'instantané différé.
- Dépendances : M1.

**M3. Valorisation et répartition d'une opération** (`feature`, `mining`, M)
- Comportement : à la création, l'Owner fixe la base (défaut à trancher en Q4), le rendement si la base est le retraitement, la part du booster et la part corpo en %. La table finale donne, par participant, la quantité, la valeur et la part.
- Exemple : `G = 1,2 Md`, booster 10 % ; le pilote A a miné 40 % de la valeur, il reçoit `1,2 Md × 0,9 × 0,4 = 432 M`, et le booster reçoit 120 M.
- Prix manquant : parts inconnues et types listés (B3).
- Fichiers : `OreValueService` (après P1), nouveau service de répartition testé unitairement, pur et sans ESI.
- Dépendances : P1, P2, M2.

**M4. Clôture, paiements marqués faits, archive** (`feature`, `mining`, S)
- Statuts open → closing (attente du ledger) → settled → archived. Chaque poste « A doit X à B » se coche, et l'opération réglée est figée, comme le projet terminé de Group Industry.
- Archive visible des seuls participants.

### Suites (par valeur décroissante)

**S1. Fleet storage déclaré et vérifié par un container corpo** (`feature`, `mining`, `corp-industry`, M)
- Le mineur déclare « tout mon minerai va au destinataire » ; on calcule les dettes par paire (B3).
- Option propre à evetools : vérifier la présence du minerai dans un container corpo nommé, via les assets corpo partagés (`GroupContainerVerificationResource`). EVE Ops reste « sur la confiance ».
- Dépendances : M3 ; sync des assets corpo à la demande.

**S2. Estimateur d'épuisement d'un site** (`feature`, `mining`, M)
- Comportement : on colle deux scans ou plus, horodatés à la réception, puis on affiche le restant par type (unités, m³, valeur) avec ETA et débit (B1). Lié à une opération, l'outil propose l'ETA depuis le débit du ledger avec un seul scan, signalée « approximative ».
- Exemple : 18 400 m³ de Spodumain à 20:10 puis 12 100 m³ à 20:40, soit 210 m³/min, épuisé vers 21:38.
- **Bloquant** : savoir si la fenêtre de résultats post-Catalyst se copie (Q5). À défaut, saisie manuelle du total affiché par le chipset.
- Dépendances : P1 (valeur), SDE (volumes).

**S3. Table « quoi miner » : valeur au m³ brut, compressé et retraité** (`feature`, `mining`, M)
- À la manière de l'Ore Table : une ligne par minerai et variante ; colonnes ISK/m³ brut, compressé et retraité (au rendement de l'utilisateur), plus l'écart par rapport au retraité ; marché Jita ou structure suivie.
- Hors périmètre : le filtre par sécurité ou région, faute de données d'emplacement des minerais dans les tables SDE importées (non vérifié dans le SDE, à confirmer).
- Dépendances : P1.

**S4. Rendement de retraitement calculé et persisté** (`feature`, `mining`, M)
- Compétences (scope skills déjà demandé), type de structure, rig, sécurité et implant, avec la formule de A6 (deux sources non CCP).
- Remplace le `localStorage` et le défaut 0,78.

**S5. Répartition au temps pondéré** (`feature`, `mining`, M)
- Saisie des temps, ou `join_time` de `/fleets/{id}/members` si le créateur est boss (boss probablement requis, non confirmé). Poids par type de vaisseau.
- Dépendances : M3, décision Q3.

**S6. Notifications d'opération** (`feature`, `mining`, S)
- Ledger de fin prêt, ETA d'épuisement à moins de 10 min, paiement reçu : Mercure + push (Notifications Hub existant).

**S7. Minage lunaire corpo : observers, extractions, taxe** (`feature`, `mining`, `corp-industry`, L)
- Rôles Accountant / Station_Manager, cache 3600 s / 1800 s. Cas d'usage distinct (taxe corpo, pas le partage d'opération). Plugins de référence sous GPL : idée seulement.

### Idées écartées

- Invitations en jeu via `POST /fleets/{id}/members` : exige le scope `write_fleet`, non demandé ; un lien suffit (EVE Ops fait pareil).
- Suivi « temps réel » au cycle : impossible, le ledger est mis en cache 600 s.
- Upload du log de flotte pour contrôler le ledger (EVE Ops Loot History) : bêta non validée par son auteur, fichier client à parser, gain faible.
- Overlay OBS (EVE Miner Hub) : public de streamers, hors cible.
- Ratting / ESS dans l'opération : le module PVE existe déjà, mélanger brouille le modèle.
- Détection de vol (Mining Manager) : spécifique aux lunes corpo, reportée avec S7.
- Taxe corpo à barèmes par catégorie de minerai : relève de S7, pas du minage en flotte.

---

## (e) Ce que nos données permettent et que les autres n'ont pas

1. **Alts sans saisie** : le compte evetools porte déjà les personnages, alors qu'EVE Ops fait ajouter les alts à la main.
2. **Fleet storage vérifiable** (S1) : assets corpo + vérification de container, quand EVE Ops reste « sur la confiance ».
3. **Croisement avec l'industrie** : « cette opération couvre 38 % du déficit de Tritanium du Stockpile » en convertissant le minerai en minéraux au rendement de l'opération, ou en taguant les entrées `industry` / `corp_project` (`MiningEntry.php:19-22`). Aucun outil comparé ne le fait.
4. **Prix structure** : valorisation au marché d'une structure suivie (déjà dans `OreValueService`), utile en nullsec où Jita n'est pas le débouché.
5. **Mercure** : tableau de l'opération mis à jour personnage par personnage pendant les syncs d'instantané.

---

## Questions pour Sylvain

- **Q1. Modèle de groupe.** (A) Entités dédiées à l'opération qui reprennent le schéma de Group Industry (lien court, rôles, statuts). (B) Extraire d'abord un « groupe » générique partagé. Reco : **A**. On n'extrait un service commun (génération du lien court, contrôle d'accès) que si la duplication se confirme, et Group Industry reste intact.
- **Q2. Source des quantités.** (A) Instantanés du ledger seuls. (B) Quantités déclarées. (C) Ledger, avec correction manuelle par un Admin. Reco : **C**.
- **Q3. Répartition par défaut.** (A) Prorata de la valeur. (B) Temps pondéré. Reco : **A** au MVP, B en suite (S5).
- **Q4. Base de valorisation par défaut.** (A) Jita buy compressé, comme EVE Ops. (B) Retraité au rendement de l'opération. (C) La « meilleure valeur » actuelle. Reco : **A** par défaut, B au choix, jamais C pour une répartition, car la base doit être unique et lisible.
- **Q5. Survey scan.** Peux-tu vérifier en jeu si la fenêtre de résultats du Mining Surveyor (avec chipset) se copie, et coller un exemple ? Sans copie, S2 passe en saisie manuelle du total ou est écartée. Options : (A) copie possible, on développe le parseur ; (B) pas de copie, saisie manuelle ; (C) on écarte S2. Reco : vérifier avant d'ouvrir S2.
- **Q6. Ordre.** (A) P1 + P2 avant le MVP. (B) En parallèle. Reco : **A**, puisque M3 dépend d'une valeur juste.
- **Q7. Glossaire.** Valides-tu les termes de P3, en particulier « Opération de minage » plutôt que « flotte » ?
