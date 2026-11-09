# Audit Market — 2026-10-11

Auditeur en lecture seule, main @ 9a16496. 18 constats : 4 P1, 8 P2, 6 P3. Aucun doublon d'issue ouverte ou fermée (exclues : #8, #22, #51 ; relues : #28, #33, #78, #81, #12, #26, #44, #45). Deux constats montrent que les correctifs de #12 et #28 n'ont pas couvert tout le module. 262 tests unitaires du module verts ; 7 classes sans test.

## P1

### 1. Contrats : la comparaison aux contrats publics similaires est toujours vide — `bug`, `market`
- Constat : `getPublicContractsForComparison()` écarte tout contrat dont `status` ≠ `outstanding`, or `/contracts/public/{region_id}/` ne renvoie pas de champ `status` (spec ESI vérifiée : buyout, collateral, contract_id, date_expired, date_issued, days_to_complete, end_location_id, for_corporation, issuer_corporation_id, issuer_id, price, reward, start_location_id, title, type, volume). `similarCount` vaut toujours 0 ; le test passe parce que sa fixture invente `status`.
- Preuve : `src/State/Provider/Contract/ContractCollectionProvider.php:175` ; fixture `tests/Unit/State/Provider/Contract/ContractCollectionProviderTest.php:436`, `:494`, `:204-216` ; filtre introduit en V0.2 (85e1ea3).
- Impact : « Contrats similaires » toujours à 0 ; `isCompetitive` retombe toujours sur Jita ±10 % (`:277-282`) sans que l'utilisateur le sache.
- Test : fixture conforme à la spec ESI (sans `status`), bon volume → `similarCount = 1`.

### 2. Une alerte de prix déclenchée n'est jamais montrée à l'utilisateur — `bug`, `market`
- Constat : `MarketAlertService` publie `{alertType, data, timestamp}` sur `/user/{id}/alerts/market-price` ; le frontend ne reconnaît que les messages avec `category`, `title`, `id` et traite les autres comme une progression de sync sans `syncType`. Aucune `Notification` enregistrée, aucun push ; la catégorie `price` est déclarée et réglable mais jamais utilisée.
- Preuve : `src/Service/MarketAlertService.php:81-91` (pas de `NotificationDispatcher`, contrairement à `PlanetarySyncService.php:307,340`) ; `frontend/src/stores/sync.ts:119-138` ; `src/Entity/Notification.php:22` (`CATEGORY_PRICE` sans appelant) ; `frontend/src/components/notifications/NotificationDropdown.vue:30`.
- Impact : l'alerte passe « triggered » sans signal ; la préférence « price » n'a aucun effet.
- Test : alerte franchie → `NotificationDispatcher::dispatch(user, 'price', …)` ; préférence `price` désactivée → bloqué.

### 3. Sync Jita : un échec ESI efface les prix et la sync est annoncée « terminée » — `bug`, `market`
- Constat : `getBatch()` met à null les types en erreur ; `fetchSuccessfulResponses()` les retire ; la sync remplace tout le cache. Un type en erreur disparaît ; une panne totale écrit un carnet vide (`[]`, compté « hit »). `success: true`, le handler ne lit pas `success` et appelle `complete`. Le correctif de #12 (da23a07) n'a couvert que minage et PVE.
- Preuve : `src/Service/JitaMarketService.php:67-79`, `:92-96`, `:1026-1031` ; `src/MessageHandler/TriggerJitaMarketSyncHandler.php:29-37` ; `src/Service/ESI/EsiClient.php:322-352`.
- Impact : 2 h sans prix pour les appelants sans repli (`getPrices()`, 8 appels dont scanner et stockpile) ; l'admin voit « 0 types » en vert.
- Test : ESI 503 pour un type sur deux → cache précédent intact pour ces types (ou sync en échec) et `SyncTracker::fail` appelé.

### 4. Sync manuelle d'une structure : cache partagé vidé avant la sync, personnage pris au hasard — `bug`, `market`
- Constat : `SyncMarketProcessor` appelle `clearCache($structureId)` avant `syncStructureMarket()` ; cache partagé entre utilisateurs ; token du premier personnage qui en a un, sans vérifier `esi-markets.structure_markets.v1`. Défaut corrigé par #28 dans le handler, resté ici.
- Preuve : `src/State/Processor/ShoppingList/SyncMarketProcessor.php:50-55`, `:73`, `:76-83` ; comparer `src/MessageHandler/SyncStructureMarketHandler.php:36`.
- Impact : sur un 403, prix structure disparus pour tous jusqu'à la sync horaire ; alertes structure muettes.
- Test : sync qui échoue → `getLowestSellPrice()` renvoie toujours l'ancien prix ; le personnage choisi a le scope.

## P2

### 5. Carnet d'ordres Jita : seule la page 1 de l'ESI est lue — `bug`, `market`
- Preuve : `src/Service/JitaMarketService.php:1040-1047`, `:989`, `:414` ; figé par `tests/Unit/Service/JitaMarketServiceEsiClientTest.php:149-163` (renvoie à #26, fermée sans changement) ; glossaire « Carnet Jita limité à la première page ESI ».
- Impact : types à plus de 1000 ordres dans The Forge : meilleur prix et prix pondéré potentiellement faux.
- Test : `X-Pages: 2`, ordre le moins cher en page 2 → le carnet commence par cet ordre.

### 6. Rafraîchissement des alertes : recrée un cache Jita réduit aux types d'alerte, nombre faux — `bug`, `market`
- Preuve : `src/Service/JitaMarketService.php:135-145`, `:148-155`, `:161` ; `src/MessageHandler/CheckAlertPricesHandler.php:226-247`.
- Impact : après une panne de sync > 2 h, presque tous les prix sans repli valent null ; « N types refreshed » à tort ; alerte évaluée sur un prix périmé.
- Test : sans cache principal, refresh de 2 types → pas de cache principal créé (ou marqué partiel) ; 2 requêtes en 503 → `typeCount = 0`.

### 7. Appraisal : des lignes collées disparaissent sans être signalées — `bug`, `market`
- Constat (vérifié en exécutant `parseItemList()`) : `"100MN Microwarpdrive II"` → `[]` ; `"100MN Microwarpdrive II 5"` → `[]` ; `"Tritanium 0"` → quantité 1 ; ligne d'inventaire multi-colonnes à tabulations → nom = toute la ligne.
- Preuve : `src/Service/ItemParserService.php:134-147`, `:113`, `:119`, `:125`, `:131`, `:139`.
- Impact : estimation d'un fit ou d'un hangar sous-évaluée sans avertissement.
- Test : `100MN Microwarpdrive II` → quantité 1 ou dans `notFound` ; ligne multi-colonnes → bon nom et bonne quantité.

### 8. Appraisal et comparaison de prix : totaux partiels présentés comme complets — `bug`, `market`
- Constat : totaux sur les seuls items pricés sans compter les autres ; total pondéré = `weightedPrice × quantité totale` même à couverture partielle ; volume SDE manquant = 0 m³ (`?? 0.0`), import à 0 (#104 ne couvre que `ShoppingListProvider`).
- Preuve : `src/State/Processor/ShoppingList/AppraiseProcessor.php:72`, `:145-148`, `:181-198` ; `src/Service/JitaMarketService.php:689-693` ; `src/State/Processor/ShoppingList/ParseProcessor.php:84`, `:156-169` ; `frontend/src/views/Appraisal.vue:217-222`.
- Test : un item sans prix → total inconnu ou nombre d'items non pricés ; couverture 0,1 → total pondéré ≠ prix moyen × quantité.

### 9. Contrats : valeur Jita/structure partielle, structure en dur « Delve / C-J6MT » — `bug`, `market`
- Preuve : `src/State/Provider/Contract/ContractCollectionProvider.php:224-232`, `:266-282` ; `src/ApiResource/Contract/ContractResource.php:79-83` ; `frontend/src/components/assets/ContractsTab.vue:48`, `:377-378` ; `config/services.yaml:8-9`.
- Impact : un contrat avec un item sans prix paraît trop cher, « non compétitif » ; une autre instance afficherait « C-J6MT ».
- Test : item sans prix Jita → `jitaValue` null, `isCompetitive` null ; libellé tiré de `structureName`.

### 10. « Variation 30 j » sur un historique jamais rafraîchi : 0 % si la donnée a plus de 30 jours — `bug`, `market`
- Preuve : `src/Service/MarketHistoryService.php:108-121` ; `src/Repository/MarketPriceHistoryRepository.php:65-82` ; appelants sans sync : `MarketSearchProvider.php:80`, `MarketFavoriteCollectionProvider.php:73`, `MarketTypeDetailProvider.php:86`.
- Test : une seule entrée à J-60 → null, pas 0.0.

### 11. Alertes structure : la cible change en silence, une alerte sans prix n'est jamais évaluée — `bug`, `market`
- Preuve : `src/Entity/MarketPriceAlert.php:22-53` (pas de colonne structure) ; `src/Service/MarketAlertService.php:64-66`, `:137-139` ; `AlertStatus::Expired` jamais posé.
- Test : changer de structure préférée ne change pas la structure évaluée ; structure sans cache → état visible.

### 12. Appraisal partagé : prix et totaux du client publiés tels quels — `bug`, `market`
- Preuve : `src/State/Processor/ShoppingList/ShareListProcessor.php:35-42` ; `src/ApiResource/Input/ShoppingList/ShareListInput.php:11-19` ; `src/Controller/SharedAppraisalController.php:35-44` (Open Graph).
- Impact : partage d'une estimation falsifiée (ex. contrat entre joueurs) sous la marque de l'outil.
- Test : POST avec `totals.sellTotal` modifié → totaux recalculés côté serveur.

## P3

13. Deux implémentations du prix Jita à la demande (`src/Service/ESI/MarketService.php:63-147`, `:202` vs `JitaMarketService.php:381-434`) ; `Appraisal.vue:241-257` appelle `/appraise` et `/parse` en parallèle ; `comparePrices()` affiche le nom de la structure par défaut quand le nom demandé est introuvable. — `tech-debt`, `market`
14. Historique : erreur ESI avalée (`MarketHistoryService.php:87-98`), ESI rappelée à chaque affichage (seuil `-1 day` vs date ESI à minuit, `EsiClient.php:43-51`), structure tracée à 0 ISK (`MarketHistoryProvider.php:95-97`, `sellMin ?? 0.0`), courbes Jita mal libellées (`MarketHistoryChart.vue:131-137`, `:158`, `:175`, `:192`). — `bug`, `market`
15. Carnet d'achat Jita : ordres à portée région ou système hors station ignorés, `min_volume` ignoré (`JitaMarketService.php:1072-1076`, `:679` ; glossaire « Carnet d'ordres »). — `investigation`, `market`
16. Code mort : `SyncStructureMarket` + handler jamais dispatchés (`config/packages/messenger.yaml:34`, testés), `JitaMarketService::getCachedDailyVolumesForRegion()` (`:462`), branches « formats legacy » (`StructureMarketService.php:154-162`, `:182`), `AlertStatus::Expired`, deux caches de volume pour The Forge (`jita_volume_`, `volume_10000002_`). — `tech-debt`, `market`
17. Index des contrats publics : contrats d'échange gardés (`is_included: false`, prix `price / qty`), `is_blueprint_copy` non lu, échecs partiels annoncés comme succès (`SyncPublicContractsHandler.php:105-126`, `:176-182`, `:42`) ; consommé par `ProfitMarginService.php:115`. — `bug`, `market`
18. Tests manquants : `AppraiseProcessor`, `ParseProcessor`, `ShareListProcessor`, `SyncMarketProcessor`, `TriggerJitaMarketSyncHandler`, `CheckAlertPricesHandler`, `PurgeOldMarketHistoryHandler` ; fixtures de contrats publics qui inventent `status` ; `MarketHistoryProviderTest` sans la source structure. — `tech-debt`, `market`
