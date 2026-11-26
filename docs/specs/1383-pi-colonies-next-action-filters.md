# Spec #1383 : colonies triées par prochaine action, filtres rapides, badge de tier

Mode nuit : validée en autonomie, décisions notées en bas. « After #1382 » : branche empilée sur #1413 (qui porte #1409 et #1412) puis fusion de la branche de #1385 (PR #1422, elle-même sur #1419) pour reprendre l'écran Colonies. Maquette locale non versionnée : `pi-production-mockup-2026-11-25.html` (barre « Trier par », puces de filtre, en-tête de compte « prochaine action dans »).

## Contexte

L'onglet Colonies liste les colonies par personnage puis type de planète : le joueur doit tout ouvrir pour trouver ce qui demande une action. Les données existent : extracteur fini (`nearestExpiry`, `status`), launchpad qui se vide (`GET /planetary/input-needs`, `launchpads[].feeds[].emptyAt` de #1382), CC à monter (`commandCenterUpgradable`, #1385). Il manque le tier de la colonie et l'état « stockage plein ».

## Prérequis vérifiés sur la branche

- `PlanetaryInputNeedsResource::$launchpads[]` porte `colonyId` et `feeds[].emptyAt` (null sans débit) ; aucun consommateur front aujourd'hui.
- Pas de projection des stocks de storage facility (hors périmètre de #1382) : « stockage plein » se lit donc à la dernière vue.
- `PlanetaryResourceMapper::resolveOutputTier()` donne le tier d'un pin d'usine (détail seulement) ; la liste n'a pas de tier par colonie.
- Pas d'écran Approvisionnement ni Stock sur cette branche : le badge de leurs lignes (issue) est livré en composant réutilisable seulement.

## Backend (`ColonyResource`, liste et détail, sans migration)

1. `tier` (`?string`) : le plus haut tier de sortie (`P1` à `P4`) des pins d'usine de la colonie, `null` sans pin d'usine ou si un schéma est illisible. Lecture des schémas mémoïsée par id dans le mapper (liste de colonies).
2. `storageFull` (`?bool`) : `true` si une storage facility (catégorie `storage`) a ses contenus (`quantité × volume SDE`) au moins égaux à sa capacité SDE à la dernière vue ; `false` si toutes les storage facilities sont lisibles et sous leur capacité, ou s'il n'y en a aucune ; `null` si aucune n'est pleine et qu'une capacité ou un volume est illisible.
3. `normalizationContext skip_null_values` déjà à `false` (#1385) : les clés nulles restent présentes.

## Frontend : logique pure (`frontend/src/utils/colonyNextAction.ts`)

4. Action d'une colonie : `restart` (colonie avec extracteur et `nearestExpiry` non nul, à l'heure `nearestExpiry`), `refill` (premier `emptyAt` des launchpads de la colonie dans input-needs), `storage_full` (`storageFull` vrai, à l'heure de la dernière vue `lastUpdate`). La prochaine action est la plus proche dans le temps (passé compris), sans action : `null`.
5. Tri `Prochaine action` (défaut) : date croissante, passé d'abord, colonies sans action en dernier, égalité dans l'ordre actuel (personnage, type de planète). `Personnage` = ordre actuel, `Système` = nom du système puis ordre actuel. En regroupement par personnage, tri dans chaque groupe, groupes triés par leur première échéance en mode `Prochaine action`.
6. Filtres rapides (un seul actif, `Toutes` par défaut) : `P1` à `P4` (champ `tier`), `À relancer` (statut `expired` ou `expiring`), `À recharger` (`refill` dans les 24 h ou déjà passé, constante `REFILL_HORIZON_HOURS`), `Stockage plein` (`storageFull` vrai), `CC à monter` (`commandCenterUpgradable` vrai). Chaque puce porte son compte ; une puce à 0 reste affichée, grisée.
7. Données de recharge absentes (input-needs en erreur ou pas encore chargé) : la puce `À recharger` affiche `?` et une infobulle, aucune action `refill` n'est inventée.

## Frontend : écran

8. Store `planetary` : `inputNeeds` (types minimaux utiles), `fetchInputNeeds()` appelée avec les autres au chargement et dans `finishSync`.
9. Barre de l'onglet Colonies : sélecteur « Trier par » (`pi.sort.*`) à côté de « Regrouper par », puis les puces. Un filtre qui ne laisse aucune colonie dit « Aucune colonie pour ce filtre » avec un bouton de retour à `Toutes`.
10. Composant réutilisable `PiTierBadge.vue` (tier `P1` à `P4`, couleurs `TIER_CONFIG`), affiché sur chaque ligne de colonie ; sans tier, rien.
11. Ligne de colonie : libellé de la prochaine action (`Extracteurs finis`, `Launchpad vide`, `Stockage plein`) et sa date relative (`dans 5 h`, `il y a 2 h` en rouge) ; en-tête de section : « prochaine action dans … » (la plus proche du groupe). i18n FR et EN (`pi.sort.*`, `pi.filters.*`, `pi.nextAction.*`), aucune clé supprimée, noms longs testés, direction HUD EVE.

## Exemples

| Colonie | Données | Action |
|---|---|---|
| A | extracteur fini dans 5 h, launchpad vide dans 9 h | `restart` dans 5 h |
| B | launchpad vide il y a 1 h | `refill` en retard, passe avant A |
| C | `storageFull` vrai | `storage_full`, date = `lastUpdate` |
| D | tier `P4`, rien d'urgent | sans action, en dernier |

## Tests

- PHP : `tier` (P1 à P4, plus haut tier, sans usine, schéma illisible), `storageFull` (vrai, faux, aucune storage, illisible), champs présents sur la liste.
- Front (au plus 15) : util (action par type, passé d'abord, sans action en dernier, filtres et comptes, recharge inconnue), vue (tri par défaut, puces, filtre vide), badge, store `fetchInputNeeds`.

## Hors périmètre

Badge de tier sur les lignes d'approvisionnement et de stock (écrans des issues #1388 à #1401, composant prêt), projection du remplissage d'une storage facility, alertes, persistance du tri ou du filtre.

## Décisions

- Issue partielle : badge des lignes d'approvisionnement et de stock non livré, donc pas de `Closes` ; commentaire sur l'issue.
- Calcul de la prochaine action côté front à partir de données déjà exposées : un seul tri, aucune nouvelle ressource.
- « Stockage plein » à la dernière vue seulement, faute de projection : la colonie passe en tête.
- Tier de colonie = plus haut tier produit par ses usines (une colonie d'extraction avec usines P1 est P1).
