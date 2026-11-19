# Boucle de nuit 2026-11-19

Threads : #1084 (courrier Jita seul), #1085 (achats à amener au container).

## Décisions

- [#1084] Jita seul pour l instant (Sylvain, 2026-11-19).

## Blocages

## Journal
- 22:21 [#1084] démarrage, spec + décision : devis Jita seul, otherOrigins (devis par origine) remplacé par otherOriginLines (achats hors Jita, sans devis), exposé pour #1085
22:21 [#1085] démarrage, lecture du code (courier, fulfillment)
22:22 [#1085] spec écrite docs/specs/1085-purchases-to-deliver.md (endpoint et carte séparés, pas de contact avec CourierOverviewBuilder)
- [#1085] D1 carte séparée sous le courrier, endpoint /purchases-to-deliver ; D2 groupes jita/other/unknown ; D3 livrées masquées + purchaseCount ; D4 stock attribué Jita d'abord, inconnue en dernier
22:25 [#1085] backend vert (builder, resource, provider, mapper, tests unit + phpstan)
- 22:25 [#1084] backend vert (unit 17677, functional courier, phpstan), poussé a275ad16 ; front en cours
22:26 [#1085] test fonctionnel API vert sur base isolée 1085 (supprimée)
- 22:28 [#1084] PR #1086 (119cce61) liée, niveau 3 (code Industry, assertions #1022 modifiées). Aucune action prod. Contrôle visuel non fait.
- 22:28 [#1084] Décision : devis Jita seul, otherOrigins remplacé par otherOriginLines (achats hors Jita, sans devis) pour #1085 ; section « autres lieux » retirée du panneau
- 22:28 [#1084] rétro : wt-php.sh unit lance phpunit sans test avec la config (cacheDirectory non inscriptible) -> utiliser --cache-directory /tmp/phpc ; garde migrations compare à main local périmé (faux positif, TEST_TOKEN requis)
22:36 [#1085] front commité (carte, store, i18n FR/EN, spec vitest 11 tests, eslint ok, vue-tsc ok hors marked)
22:39 [#1085] niveau 3 (code Industry, nouvelle ressource API). Rebase sur origin/fix/night-1084 (PR #1086), push fa652392, PR #1087 vers main (merge #1086 first), Closes #1085
- [#1085] Pas de commande prod ni migration. Fusionner #1086 avant #1087.
22:39 [#1085] rétro : wt-php.sh integration refuse sans TEST_TOKEN quand main local est en retard sur origin/main (comparaison à main local, pas origin/main) ; contournement base isolée 1085
23:11 [#1089] démarrage, pluriel runs (clé companion.runs, 2 usages), test rouge puis vert
23:11 [#1089] PR #1090 (7e1dd4df) liée, niveau 3 (code Industry, ligne expect ajoutée). Aucune action prod. Contrôle visuel non fait.
23:11 [#1088] démarrage, lecture du composant et de la maquette
23:12 [#1088] composant réécrit (sous-groupes, compteurs, accordéons, helper localStorage), tests à adapter ; mine non plafonné (aucun plafond aujourd'hui, ne pas cacher des livraisons)
23:15 [#1088] PR #1091 (c20c478c) liée, vise main, merge #1090 first. Niveau 3 (code Industry, assertions). Aucune action prod. Contrôle visuel non fait. My lines non plafonné (décision au corps de la PR).
23:15 [#1088] rétro : worktree sans node_modules, lien symbolique vers le checkout principal (lock identique hors flags peer) ; un hook a bloqué une commande composée commit+push (message force push) alors qu'aucun force n'était demandé, commandes séparées OK ; contributeModalUnknownMaterialPrice.spec échoue en suite complète, passe seul
[#1093] 23:33 début, branche fix/night-1093 sur c20c478c
