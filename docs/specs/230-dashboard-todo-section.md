# Spec #230 — Dashboard : section « À faire »

## Contexte

Le Dashboard affiche deux KPI (personnages, wallet), les cartes des personnages et un flux d'activité temps réel. Il ne dit pas au joueur ce qui demande une action maintenant. Les informations existent mais sont réparties entre Characters, PI, Market et Industry. La section « À faire » les rassemble en haut du Dashboard, une ligne par catégorie, chacune avec un lien vers l'écran où agir.

Sources vérifiées dans le code (2026-10-26) :

| Catégorie | Source existante | État |
|---|---|---|
| Personnages à reconnecter | `GET /api/me` (`authStore.user`) : `characters[].hasValidToken`, `characters[].hasMissingScopes`, `authStatus` | Disponible, avec une limite : `hasValidToken` vaut `false` pour un token absent, illisible ou sans tous les scopes, mais **pas** pour une autorisation révoquée (spec #86). Une révocation n'est visible qu'au niveau utilisateur (`authStatus = invalid`), sans savoir quel personnage. |
| Extracteurs PI arrêtés / qui expirent | `GET /api/planetary/stats` (`usePlanetaryStore().fetchStats()`) : `expiredExtractors`, `expiringExtractors` (fenêtre de **24 h**, `PlanetaryPin::isExpiringSoon(24)`) | Disponible. `totalColonies = 0` ne distingue pas « aucune colonie » de « jamais synchronisé ». |
| Alertes de prix déclenchées | `GET /api/market/alerts` (`useMarketStore().fetchAlerts()`) : `status = 'triggered'` | Disponible. Une alerte déclenchée le reste jusqu'à sa suppression (aucun acquittement). |
| Jobs terminés à livrer | Aucune. `GET /api/industry/slots` écarte explicitement les jobs `ready` et les jobs `active` dont la date de fin est passée (`SlotTrackerService::getSlotStatus()`). Aucun autre endpoint ne liste les jobs du joueur. | **Endpoint manquant** (backend, hors périmètre du thread ui-shell). |
| Matériaux manquants d'un projet | Aucune donnée simple | Hors périmètre (l'issue le prévoit « plus tard »). |

Temps réel vérifié :
- Sync `planetary` : `PlanetarySyncService` publie `syncCompleted` par personnage sur `/user/{userId}/sync/planetary` (planifié toutes les 30 min).
- Topics `/user/{userId}/alerts/planetary-expiry` et `/alerts/market-price` : publiés, auxquels le front est abonné, mais **mal routés** : le payload (`alertType`, `data`, `timestamp`) n'a ni `syncType` ni `category`, et `stores/sync.ts` l'écrit dans `syncStatus['undefined']`. Aucun écran ne les exploite.
- `MarketAlertService` n'envoie pas de notification in-app : seul le topic `alerts/market-price` signale un déclenchement.
- Sync `industry-job-completed` : publiée, utilisée par `useNotificationFeed`.

## Règles

- **R1 — Emplacement.** La section « À faire » est le premier bloc du Dashboard, sur toute la largeur, au-dessus des KPI et du flux d'activité, y compris sur mobile (une colonne).
- **R2 — Une ligne par catégorie non vide.** Une catégorie dont le compteur vaut 0 n'a pas de ligne. Chaque ligne porte un compteur, un libellé au pluriel correct et un lien :

  | # | Catégorie | Condition | Niveau | Lien |
  |---|---|---|---|---|
  | C1 | Personnages à reconnecter | au moins un personnage avec `hasValidToken = false`, ou `authStatus = 'invalid'` | critique | `/characters` |
  | C2 | Extracteurs PI arrêtés | `expiredExtractors > 0` | critique | `/planetary` |
  | C3 | Extracteurs PI qui expirent | `expiringExtractors > 0` (moins de 24 h) | avertissement | `/planetary` |
  | C4 | Alertes de prix déclenchées | au moins une alerte `status = 'triggered'` | info | `/market?tab=alerts` |

- **R3 — Ordre fixe.** Les lignes suivent l'ordre C1, C2, C3, C4, quel que soit leur compteur. Une ligne « inconnu » garde la position de sa catégorie.
- **R4 — Compteur des personnages à reconnecter.** Le compteur est le nombre de personnages avec `hasValidToken = false` ; la ligne cite leurs noms dans l'ordre de `/me` (main d'abord, puis alphabétique). Si `authStatus = 'invalid'`, un personnage révoqué peut ne pas être dans ce compte : la ligne affiche « au moins N » avec N = max(1, compteur), et ne cite les noms que s'ils sont connus.
- **R5 — Source indisponible = inconnu.** Si le dernier chargement d'une source échoue, sa catégorie affiche une ligne « inconnu » avec son lien, jamais 0 et jamais l'absence de ligne. Les catégories C2 et C3 partagent une source : elles forment alors une seule ligne « Extracteurs PI : inconnu ». Une valeur obtenue avant l'échec n'est pas réaffichée.
- **R6 — « Tout est à jour ».** L'état discret « Tout est à jour » ne s'affiche que si toutes les sources sont chargées avec succès et que toutes les catégories sont vides. Pendant un chargement, ou si une catégorie est inconnue, il ne s'affiche pas.
- **R7 — Chargement.** Tant qu'une source n'a pas répondu, la section affiche un indicateur de chargement à la place de ses lignes manquantes, jamais « Tout est à jour ».
- **R8 — Mise à jour en direct.**
  - `syncStatus['planetary']` qui passe à `completed` : rechargement de `/planetary/stats`.
  - message Mercure `alertType = 'market-price'` : rechargement de `/market/alerts`. Cela suppose de router les messages `alertType` dans `stores/sync.ts` au lieu de les écrire sous `syncStatus['undefined']` (changement front, dans le périmètre).
  - message Mercure `alertType = 'planetary-expiry'` : rechargement de `/planetary/stats`.
  - Personnages à reconnecter : pas d'événement Mercure ; la ligne suit `authStore.user`, rafraîchi au retour d'EVE SSO.
- **R9 — Libellés i18n.** Clés sous `dashboard.todo.*`, en FR et en EN, au pluriel (syntaxe vue-i18n `singulier | pluriel`, comme `dashboard.kpi.partialTotal`).

  | Clé proposée | EN | FR |
  |---|---|---|
  | `title` | To do | À faire |
  | `charactersToReconnect` | {count} character to reconnect \| {count} characters to reconnect | {count} personnage à reconnecter \| {count} personnages à reconnecter |
  | `atLeastCharactersToReconnect` | At least {count} character to reconnect \| At least {count} characters to reconnect | Au moins {count} personnage à reconnecter \| Au moins {count} personnages à reconnecter |
  | `expiredExtractors` | {count} PI extractor stopped \| {count} PI extractors stopped | {count} extracteur PI arrêté \| {count} extracteurs PI arrêtés |
  | `expiringExtractors` | {count} PI extractor expires within 24 h \| {count} PI extractors expire within 24 h | {count} extracteur PI expire dans moins de 24 h \| {count} extracteurs PI expirent dans moins de 24 h |
  | `triggeredPriceAlerts` | {count} price alert triggered \| {count} price alerts triggered | {count} alerte de prix déclenchée \| {count} alertes de prix déclenchées |
  | `unknownCharacters` | Characters to reconnect: unknown | Personnages à reconnecter : inconnu |
  | `unknownExtractors` | PI extractors: unknown (loading failed) | Extracteurs PI : inconnu (échec du chargement) |
  | `unknownPriceAlerts` | Price alerts: unknown (loading failed) | Alertes de prix : inconnu (échec du chargement) |
  | `allCaughtUp` | All caught up | Tout est à jour |

## Exemples chiffrés (tests rouges Vitest)

Données communes : utilisateur avec le main Ilyra Castell et l'alt Doran Vex. Par défaut : `authStatus = 'valid'`, les deux `hasValidToken = true` ; `/planetary/stats` à `expiredExtractors = 0`, `expiringExtractors = 0`, `totalColonies = 3` ; `/market/alerts` avec une alerte `active`. Locale EN sauf mention. Chaque ligne attendue est donnée dans l'ordre d'affichage avec son lien.

| # | État des données (écart au défaut) | Section attendue |
|---|---|---|
| E1 | aucun | « All caught up », aucune ligne |
| E2 | `expiringExtractors = 2` (cas de l'issue) | 1 ligne : « 2 PI extractors expire within 24 h » → `/planetary` (avertissement) ; pas de « All caught up » |
| E3 | `/planetary/stats` répond 500 | 1 ligne : « PI extractors: unknown (loading failed) » → `/planetary` ; aucun « 0 », pas de « All caught up » |
| E4 | Doran `hasValidToken = false` | 1 ligne : « 1 character to reconnect », cite Doran Vex → `/characters` (critique) |
| E5 | Ilyra et Doran `hasValidToken = false`, `hasMissingScopes = true` | 1 ligne : « 2 characters to reconnect », cite Ilyra Castell puis Doran Vex → `/characters` |
| E6 | `authStatus = 'invalid'`, les deux `hasValidToken = true` | 1 ligne : « At least 1 character to reconnect », sans nom → `/characters` |
| E7 | `authStatus = 'invalid'`, Doran `hasValidToken = false` | 1 ligne : « At least 1 character to reconnect », cite Doran Vex |
| E8 | Doran `hasValidToken = false` ; `expiredExtractors = 1`, `expiringExtractors = 3` ; alertes : 2 `triggered`, 1 `active` | 4 lignes dans l'ordre : « 1 character to reconnect » → `/characters` ; « 1 PI extractor stopped » → `/planetary` ; « 3 PI extractors expire within 24 h » → `/planetary` ; « 2 price alerts triggered » → `/market?tab=alerts` |
| E9 | E8 en locale FR | « 1 personnage à reconnecter » ; « 1 extracteur PI arrêté » ; « 3 extracteurs PI expirent dans moins de 24 h » ; « 2 alertes de prix déclenchées » ; titre « À faire » |
| E10 | `/market/alerts` répond 500 | 1 ligne : « Price alerts: unknown (loading failed) » → `/market?tab=alerts` ; pas de « All caught up » |
| E11 | `/market/alerts` répond 500 et `expiredExtractors = 1` | 2 lignes dans l'ordre : « 1 PI extractor stopped » ; « Price alerts: unknown (loading failed) » |
| E12 | `/planetary/stats` en attente de réponse, le reste au défaut | indicateur de chargement, pas de « All caught up » |
| E13 | `expiringExtractors = 2` affiché, puis `syncStatus['planetary']` passe à `completed` et `/planetary/stats` répond `expiringExtractors = 0` | `/planetary/stats` appelé une 2e fois ; la ligne PI disparaît ; « All caught up » |
| E14 | aucune alerte déclenchée, puis message Mercure `{ alertType: 'market-price', … }` et `/market/alerts` répond 1 `triggered` | `/market/alerts` appelé une 2e fois ; ligne « 1 price alert triggered » ; `syncStatus` n'a pas de clé `undefined` |
| E15 | alertes : 1 `expired`, 1 `active` | « All caught up » (une alerte expirée n'est pas à faire) |
| E16 | `/planetary/stats` à `totalColonies = 0` | « All caught up » (limite connue, voir cas limites) |

## Cas limites

- **Plusieurs personnages.** C2, C3 et C4 sont des totaux sur tous les personnages de l'utilisateur ; pas de détail par personnage en v1 (`/planetary/stats` est agrégé). C1 cite les personnages concernés.
- **Token expiré.** Un access token expiré n'est pas « à reconnecter » : il est rafraîchi automatiquement et `hasValidToken` reste `true`. Seuls comptent un token absent, illisible ou sans tous les scopes (`hasValidToken = false`) et une autorisation révoquée (`authStatus = 'invalid'`, R4).
- **Personnage révoqué non identifiable.** `/me` ne dit pas quel personnage est révoqué (spec #86, hors périmètre de l'époque) : la ligne dit « au moins N » sans le nom. Le lien mène à Characters, qui ne l'identifie pas non plus aujourd'hui.
- **PI jamais synchronisée.** `/planetary/stats` ne distingue pas « aucune colonie » de « jamais synchronisé ». v1 : pas de ligne PI dans ce cas (E16). C'est une exception à la règle « jamais synchronisé = inconnu », imposée par l'absence de donnée ; la lever demande un champ backend [À CONFIRMER : champ et forme].
- **Colonies d'un personnage révoqué.** Ses extracteurs restent dans les compteurs avec des dates figées à la dernière sync : un extracteur relancé en jeu depuis peut apparaître « arrêté ». La ligne C1 signale le personnage à reconnecter en tête.
- **Passage du temps sans sync.** Un extracteur qui franchit le seuil (24 h ou arrêt) entre deux syncs n'est recompté qu'au rechargement suivant (ouverture du Dashboard ou fin de sync `planetary`, au plus 30 min).
- **Alerte déclenchée persistante.** Une alerte `triggered` reste à faire jusqu'à sa suppression dans Market › Alerts ; aucun acquittement n'existe.
- **Mercure déconnecté.** Les compteurs restent ceux du dernier chargement ; le bandeau d'erreur Mercure existant reste affiché. Pas d'état « inconnu » pour une simple déconnexion.
- **`authStore.user` absent après chargement.** C1 affiche « Characters to reconnect: unknown ».

## Hors périmètre

- **Jobs terminés à livrer** : aucun endpoint ne les expose (voir question 1). Règle prévue une fois l'endpoint disponible : catégorie entre C3 et C4, compteur = jobs du joueur `ready` ou `active` à date de fin passée, non `delivered`, lien `/industry?tab=slots`, inconnu si les jobs d'un personnage sont périmés (`jobsStale`).
- Matériaux manquants d'un projet industriel.
- Tout changement backend (nouvel endpoint, champ, seuil 6 h, statut d'autorisation par personnage).
- Notifications ESI in-game, escalations, contrats.
- Détail par personnage des extracteurs et des alertes.
- Acquittement d'une alerte de prix depuis le Dashboard.
- Fraîcheur affichée des données (#229) et temps réel des écrans industrie (#215).

## Termes du glossaire proposés

À ajouter à `docs/glossary.md` après validation (non modifié par cette spec) :

| Terme FR | Term EN | Définition proposée | Symbole(s) |
|---|---|---|---|
| **À faire** | To do | Section du Dashboard qui liste, par catégorie, ce qui demande une action du joueur maintenant, avec un lien vers l'écran où agir. Une catégorie dont la source est indisponible est inconnue, jamais vide (spec #230). | `dashboard.todo.*` |
| **Personnage à reconnecter** | Character to reconnect | Personnage dont le token est absent, illisible ou sans tous les scopes requis (`hasValidToken = false`), ou dont l'autorisation est révoquée. Un access token expiré n'en fait pas un personnage à reconnecter. | `CharacterResource::$hasValidToken`, `$hasMissingScopes`, `UserResource::$authStatus` |
| **Extracteur arrêté** | Stopped extractor | Extracteur PI dont la date d'expiration est passée. | `PlanetaryStatsResource::$expiredExtractors` |
| **Extracteur qui expire** | Expiring extractor | Extracteur PI non arrêté qui expire dans moins de 24 h. | `PlanetaryStatsResource::$expiringExtractors`, `PlanetaryPin::isExpiringSoon()` |
| **Alerte de prix déclenchée** | Triggered price alert | Alerte de prix dont le seuil a été franchi ; elle le reste jusqu'à sa suppression. | `AlertStatus::Triggered`, `MarketPriceAlert::trigger()` |
| **Job à livrer** | Job to deliver | Job ESI `ready`, ou `active` dont la date de fin est passée, pas encore `delivered`. Attention : **Job en cours** inclut aujourd'hui `ready` ; à harmoniser à l'ajout du terme. | aucun symbole (endpoint manquant) |

## Questions pour Sylvain (gate 🚦)

1. **Périmètre v1 et jobs terminés.** Aucun endpoint n'expose les jobs à livrer. Recommandation : livrer v1 avec C1 à C4 (front seul) et ouvrir une issue backend qui ajoute à `/industry/slots` un compteur de jobs à livrer par personnage (le service charge déjà ces jobs puis les écarte). Alternative : attendre l'endpoint et livrer les cinq catégories ensemble.
2. **Endpoint agrégé ou composition de stores.** Recommandation : composition des trois sources existantes (`/me`, `/planetary/stats`, `/market/alerts`) dans un composable dédié, avec un état de chargement propre à chaque source, car les `error` des stores `planetary` et `market` sont partagés entre actions. Un `GET /api/me/todo` agrégé deviendrait utile si le backend doit de toute façon exposer les jobs à livrer, le statut révoqué par personnage et « PI jamais synchronisée » ; il relève d'une issue backend séparée.
3. **Seuil PI et ordre de priorité.** L'issue parle de 6 h ; `/planetary/stats` compte à 24 h et les alertes PI partent à 2 h. Recommandation : 24 h en v1 (aucun backend), et l'ordre fixe personnages à reconnecter > extracteurs arrêtés > extracteurs qui expirent > (jobs à livrer) > alertes de prix, parce qu'un personnage à reconnecter fige toutes ses autres données.
