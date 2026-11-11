# #350 — Mes ordres de vente sous-cotés

Issue : « Industrie : mes ordres de vente sous-cotés » (group:unified-industry, circuit:m). Maquette `industry-project-v3.html#p=32` (carte « Mes ordres de vente » de l'onglet Ventes, ligne de file d'actions, alerte du compagnon). Décision de Sylvain (2026-11-10) : le scope `esi-markets.read_character_orders.v1` est ajouté aux scopes requis, les utilisateurs se reconnectent après le déploiement, lecture seule (aucun ordre n'est jamais modifié depuis evetools).

Mode nuit : spec validée en autonomie. Niveau de technicité visé : 3 (code Industry, nouveau scope ESI, sync planifiée, migration).

## Prérequis vérifiés sur origin/main (234a6f4c)

`AuthenticationService::REQUIRED_SCOPES` (29), `Character::getEveToken()`, `EveToken::hasScope()`, `CharacterSyncFailure::missingScope()`, `JitaMarketService` (livres d'ordres Jita 4-4 en cache, `refreshPricesForTypes()`), `StructureMarketService::getSellOrders()` et `hasCachedData()`, `ProjectPriceAlertReader` et sa chaîne (#349), onglet Ventes (`SalesTable`), file d'actions (`IndustryActionsProvider`), compagnon (`companion.ts`), `SyncScheduler`, `SyncTracker`, `MercurePublisherService::getTopicsForUser()`. Absents (à créer) : lecture ESI des ordres du personnage, table des ordres, comparaison au meilleur prix.

## Règles

1. **Scope.** `EveConstants::CHARACTER_ORDERS_SCOPE = 'esi-markets.read_character_orders.v1'` rejoint `REQUIRED_SCOPES` (29 devient 30). Les personnages sans ce scope sont « à reconnecter » (déjà géré par les trois providers qui comparent aux scopes requis). Docs à passer à 30 : `CLAUDE.md`, `README.md`, `.claude/skills/esi-api/SKILL.md`.
2. **Lecture ESI.** `CharacterOrdersService::getSellOrders(Character)` appelle `GET /characters/{id}/orders/` (jeton du personnage) et ne garde que les ordres de vente (`is_buy_order` absent ou faux). Champs lus : `order_id`, `type_id`, `location_id`, `price`, `volume_remain`, `volume_total`, `issued`. Une réponse sans l'un de ces champs lève une exception explicite (jamais de valeur par défaut).
3. **Ordres concernés.** Seuls les ordres dont le `type_id` est un produit d'un projet de l'utilisateur sont conservés : projet non terminé, hors usage personnel, utilisateur participant accepté. Aucun projet concerné : aucune lecture ESI du marché pour cet utilisateur.
4. **Marchés suivis.** Livre d'ordres de vente du lieu de l'ordre : Jita 4-4 (`EveConstants::JITA_STATION_ID`, livre rafraîchi pour les types concernés par `JitaMarketService::refreshPricesForTypes()` avant la comparaison) ou une structure dont le marché est en cache (`StructureMarketService::hasCachedData()`, instantané horaire). Tout autre lieu, ou un livre vide pour le type : position **inconnue** avec sa cause (`market_not_followed`, `no_market_data`), jamais « 1er » par défaut.
5. **Position.** `rang = 1 + nombre d'entrées du livre au prix strictement inférieur au mien` ; `meilleur prix = min(prix du livre, mon prix)` ; l'ordre est **sous-coté** si `rang > 1`. Un prix égal n'est pas sous-coté. Mon propre ordre figure dans le livre : il ne se compte pas (prix égal, pas strictement inférieur).
6. **Prix à copier.** Pour un ordre sous-coté : `meilleur prix − UNDERCUT_STEP` avec `UNDERCUT_STEP = 100` ISK (valeur de la maquette). Nul (bouton masqué) si le résultat est ≤ 0 ou si l'ordre n'est pas sous-coté. Evetools ne modifie rien : le bouton copie le prix dans le presse-papiers.
7. **Depuis quand.** `undercutSince` est posé à l'heure de la synchro qui constate la sous-cotation, conservé tant que le même `order_id` reste sous-coté, remis à nul dès qu'il ne l'est plus ou que sa position devient inconnue.
8. **Synchro.** Tâche planifiée toutes les 5 min (`SyncCharacterOrders`, un message par utilisateur actif comme `SyncBlueprints`), `SyncTracker` `character-orders`, topic Mercure `/user/{userId}/sync/character-orders` (déclaré dans `getTopicsForUser()`) : `syncStarted`, `syncCompleted` ou `syncError`. Les lignes `CachedCharacterOrder` d'un personnage sont remplacées à chaque synchro réussie ; l'échec d'un personnage (`CharacterSyncFailure`) ne bloque pas les autres et laisse ses lignes en l'état. Personnage sans scope ou sans jeton valide : ignoré.
9. **Ressource.** `GET /industry/projects/{projectId}/sell-orders` (API Platform, `uriVariables` avec `Link`), réservée aux participants acceptés du projet (même contrôle que les ventes), et ne renvoie que les ordres des personnages de l'utilisateur connecté, restreints aux produits du projet. Contenu : `orders` (id, personnage, type, quantité restante, marché, mon prix, meilleur prix ou nul, rang ou nul, état `first` | `undercut` | `unknown`, cause d'inconnu, `undercutSince`, prix à copier ou nul), `charactersToReconnect` (noms des personnages de l'utilisateur sans le scope), `syncedAt`. Ordre de tri : sous-cotés d'abord (le plus ancien en premier), puis inconnus, puis premiers.
10. **Projet non vendable.** Projet en usage personnel ou terminé : aucun ordre, ni carte, ni alerte (comme #349).
11. **Front.** Onglet Ventes : carte « Mes ordres de vente » (colonnes Ordre, Marché, Mon prix, Meilleur prix, Position, bouton « Copier le prix ») au-dessus de `SalesTable`, état violet pointillé avec la cause pour un ordre inconnu, invitation à se reconnecter pour `charactersToReconnect`, rafraîchie sur l'événement Mercure `character-orders`. Le cockpit expose `undercutOrders` (les ordres sous-cotés du viewer). File d'actions : une ligne `undercut_sell_order` par ordre sous-coté (sévérité `warning`, avant les prochaines actions, après les alertes de prix) ; compagnon : alerte visuelle sans son sur le premier ordre sous-coté.

## Exemples (ce seront les tests rouges)

| Livre (prix) | Mon prix | Rang | Meilleur | État | Prix à copier |
|---|---|---|---|---|---|
| 900, 950, 1000 | 1000 | 3 | 900 | sous-coté | 800 |
| 1000, 1100 | 1000 | 1 | 1000 | premier | nul |
| 1000, 1000 | 1000 | 1 | 1000 | premier (égalité) | nul |
| 950 | 1000 (absent du livre) | 2 | 950 | sous-coté | 850 |
| 1200 | 1000 | 1 | 1000 | premier | nul |
| 150 | 200 | 2 | 150 | sous-coté | 50 |
| 100 | 150 | 2 | 100 | sous-coté | nul (0) |
| livre vide | 1000 | nul | nul | inconnu `no_market_data` | nul |
| lieu Amarr | 1000 | nul | nul | inconnu `market_not_followed` | nul |

Depuis quand : synchro T0 sous-coté → `undercutSince = T0` ; synchro T0+5 min toujours sous-coté → `T0` ; T0+10 min premier → nul ; T0+15 min sous-coté → `T0+15`. Ordre disparu de l'ESI : ligne supprimée.

## Cas limites

- Ordre d'achat : ignoré. Ordre dont le type n'est pas un produit d'un projet concerné : ignoré, pas de lecture de marché.
- Même type vendu par deux personnages dans deux projets : une ligne par ordre ; chaque projet ne voit que ses produits.
- Livre Jita sans entrée au prix de l'ordre (livre plus ancien que l'ordre) : position calculée sur les prix du livre, jamais d'erreur.
- ESI 420/429/5xx pour un personnage : échec isolé, `syncError` seulement si aucun personnage n'est synchronisé.
- Structure sans accès (aucun instantané) : `market_not_followed`.
- Plusieurs produits finaux : tous les types de produits comptent.

## Accès et fraîcheur

Participant accepté du projet obligatoire ; seuls les ordres des personnages de l'utilisateur connecté sont renvoyés, jamais ceux d'un autre participant. Fraîcheur : ordres ESI jusqu'à 5 min (cache ESI), Jita jusqu'à 5 min, structures jusqu'à 1 h (instantané horaire existant). `syncedAt` est exposé pour que le front dise l'âge des données. Un ex-participant n'a plus accès (statut vérifié à chaque lecture). Un personnage supprimé supprime ses lignes (cascade).

## Tests existants modifiés

- Tests comptant les scopes requis ou comparant la liste exacte (`AuthenticationService*ScopeTest`) : à vérifier au rouge, aucune assertion métier affaiblie attendue.
- Fixtures typées du cockpit et des actions (`projectCockpitFixtures`, `industryActionFixtures`, `ProjectCockpitProviderTest`, `IndustryActionsProviderTest`) : champ `undercutOrders` ajouté.

## Hors périmètre

Modifier ou annuler un ordre ; ordres d'achat ; marchés autres que Jita 4-4 et structures en cache (autres stations NPC : inconnu avec cause) ; notification push ou in-app (alerte visuelle seulement) ; ordres hors projets ; rafraîchissement forcé à la demande ; réglage du pas de sous-cotation.

## Décisions prises en autonomie (à valider par Sylvain)

- Pas de sous-cotation de 100 ISK (maquette), appliqué tel quel ; prix à copier nul si le résultat est ≤ 0.
- Marchés limités à Jita 4-4 et aux structures en cache ; les autres lieux restent « inconnus » avec leur cause.
- Persistance en table (migration) pour que « sous-coté il y a » soit vrai et que la page ne déclenche aucune lecture ESI.

## Glossaire ajouté

**Ordre sous-coté** (Undercut order) : ordre de vente d'un personnage de l'utilisateur, pour un produit d'un projet vendable, dont au moins un ordre de vente du même lieu est à un prix strictement inférieur ; inconnu avec sa cause (jamais « premier ») quand le marché n'est pas suivi ou le livre vide. Symboles : `UndercutAssessor`, `CachedCharacterOrder`, `ProjectSellOrdersResource`, code `undercut_sell_order`.
