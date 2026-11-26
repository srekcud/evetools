# #933 — PI : layouts de colonie (template du jeu)

Terme : **layout de colonie** (EN *colony layout*) = un template de colonie du jeu importé ou publié par un joueur. « Template » seul reste réservé à `PlanetaryPlanTemplate` (plan de production, #1405). Deux lots empilés : **A** (import, résumé, export, mes layouts) puis **B** (publication, bibliothèque publique). Aucun scope ESI, aucune sync, aucune action asynchrone : pas de Mercure.

## Format du template du jeu (lu sur 3 fichiers DalShooth, rien copié)
JSON objet, clés triées : `CmdCtrLv` (int 0-5, niveau du command center), `Cmt` (nom), `Diam` (diamètre de la planète d'origine), `Pln` (typeId du type de planète, groupe SDE 7), `P` pins, `L` liens, `R` routes.
- Pin : `T` typeId du bâtiment, `S` (null, ou typeId du produit pour un processeur, typeId du P0 extrait pour une ECU), `La`/`Lo` radians, `H` têtes (ECU).
- Lien : `S`/`D` indices de pins **base 1**, `Lv` niveau 0-5. Route : `P` chemin d'indices base 1, `Q` quantité, `T` typeId routé.
- Observé : `S` d'un processeur vaut le typeId du produit (9832 Coolant), pas un schematic id ; le command center n'est pas dans `P`.

## Règles du lot A
1. **Entrée** : texte JSON ≤ 65 536 octets, objet. Sinon 422 avec la liste des erreurs `{path, code}` (toutes, pas la première).
2. **Validation** : `CmdCtrLv` int 0-5 ; `Diam` nombre > 0 ; `Pln` typeId du groupe 7 présent dans le SDE ; `P` 1 à 200 pins ; `T` d'un groupe 1027, 1028, 1029, 1030 ou 1063 présent dans le SDE (sinon `unknown_installation`) ; processeur (1028) : `S` = typeId de sortie d'un schematic SDE (sinon `unknown_product`) ; ECU (1063) : `S` = typeId d'un groupe 1032, 1033 ou 1035, `H` int 1-10 ; autres pins : `S` null, `H` 0 ou absent ; `La`/`Lo` nombres. `L` 0 à 400, `S`/`D` dans 1..N, `S` ≠ `D`, `Lv` 0-5. `R` 0 à 600, `P` ≥ 2 indices valides dont chaque paire consécutive est un lien (dans un sens ou l'autre), `Q` int 1 à 10 000 000, `T` typeId SDE d'un groupe 1032-1035, 1040-1042. Au moins un processeur ou une ECU (`empty_layout`).
3. **Normalisation** : seules les clés connues sont conservées (clés inconnues ignorées, pas d'erreur), dans l'ordre du jeu. Ce JSON normalisé est ce qui est stocké et réexporté.
4. **Nom** : `name` saisi, sinon `Cmt`, rogné, 1 à 100 caractères ; vide → erreur `name_blank`.
5. **Résumé**, calculé à la lecture depuis le SDE, jamais stocké : type de planète (id, nom), niveau de command center, nombre de pins par rôle (`extractorControlUnits`, `extractorHeads`, `processors`, `storages`, `launchpads`), `links`, `routes`, charge `{cpu, powergrid}` = somme des charges des bâtiments (processeurs, stockages, launchpads, ECU + têtes ; command center et **liens exclus**, leur coût dépend de la longueur), produits `[{typeId, typeName, tier}]` (sorties distinctes des processeurs), extraits `[{typeId, typeName}]` (P0 des ECU), compétences `[{name: "Command Center Upgrades", level}]` si `CmdCtrLv` > 0. Une charge dont un attribut SDE manque vaut `null` (jamais 0) ; un nom absent du SDE vaut `null`.
6. **API** (ressource `PlanetaryColonyLayout`, `ROLE_USER`, tout scopé à l'utilisateur, l'objet d'un autre = 404) : `GET /planetary/layouts`, `GET /planetary/layouts/{id}`, `POST /planetary/layouts` (`template` texte, `name` optionnel), `PATCH` (`name`, merge-patch), `DELETE` (provider obligatoire). Au plus 100 layouts par utilisateur (101e = 422). Champs : `id`, `name`, `summary`, `gameTemplate` (objet normalisé), `createdAt`.
7. **Export** : le front copie ou télécharge `gameTemplate` avec `Cmt` = `name` (`<nom>.json`), sans appel supplémentaire.
8. **Front** : onglet `layouts` (« Layouts ») dans le module PI. Blocs : Importer (zone de collage, nom facultatif, erreurs listées), Mes layouts (carte par layout : nom, planète, niveau, pins, CPU/PG, produits, actions Copier / Télécharger / Renommer / Supprimer), Collections communautaires (5 liens externes `rel="noopener noreferrer"` : DalShooth/EVE_PI_Templates, MetalGear32/evepi, TheLegi0n-NBI/PlayingWithPP, wiki EVE University PI Templates, Industrial EVE Colony Builder ; aucun fichier tiers hébergé). i18n FR et EN, style HUD de l'onglet voisin. Un état vide explicite quand aucun layout.

## Règles du lot B (migration distincte)
9. Colonne `public` (défaut faux) et `publishedAt` sur le layout ; `PATCH` accepte `public` (booléen) pour le propriétaire seul ; publier met `publishedAt`, dépublier le vide. Un layout privé reste invisible de tout autre utilisateur.
10. `GET /planetary/layout-library` (ressource `PlanetaryColonyLayoutLibrary`, route distincte de `/planetary/layouts/{id}`) : layouts publics de tous les utilisateurs connectés, les plus récents d'abord, 200 au plus. Chaque ligne : `id`, `name`, `summary`, `gameTemplate`, `publishedAt`, `mine` (booléen). **Aucun champ d'auteur** : ni id d'utilisateur, ni nom de personnage, ni nom de compte, à aucun niveau de la réponse. Dépublier ou supprimer retire la ligne tout de suite.
11. **Front** : bloc Bibliothèque publique dans l'onglet, filtre par type de planète et par produit (texte), bouton « Ajouter à mes layouts » (POST du `gameTemplate` sérialisé, nom conservé) et Copier ; sur « Mes layouts », bascule Publier / Dépublier.

## Exemple chiffré (fixture à écrire à la main)
Planète Barren (`Pln` 2016), niveau 5, 1 launchpad (2544), 2 processeurs (2474, `S` 9832), 2 liens launchpad↔processeur (`Lv` 0), 2 routes `[2,1]` `Q` 40 `T` 3645 → résumé : `launchpads` 1, `processors` 2, `links` 2, `routes` 2, produits `[Coolant P2]`, compétence Command Center Upgrades 5, charge = 1 launchpad + 2 processeurs. Variantes : lien manquant entre deux pins d'une route → `route_not_linked` ; `S` 3645 sur un processeur → `unknown_product` ; 201 pins → `too_many_pins`.

## Cas limites
- JSON invalide ou non objet : une seule erreur `invalid_json`. Corps dépassant la limite : `too_large`.
- Pin dont le `T` est absent du SDE importé : `unknown_installation` (jamais ignoré).
- Layout sans produit connu du SDE pour un nom : résumé rendu, nom `null`.
- Doublons permis (même JSON importé deux fois = deux layouts).
- Suppression du compte : les layouts de l'utilisateur partent avec lui (`onDelete: CASCADE`), publics compris.

## Hors périmètre
Suggestion de layout par colonie ou plan suivi, édition des pins, CPU/PG des liens, compétence Interplanetary Consolidation, modération des noms, import d'un fichier (collage seulement), hébergement de templates tiers.

## Décisions prises en autonomie (journal)
`S` = typeId du produit (observé sur les fichiers), pas de schematic id ; plafond 100 layouts par utilisateur ; liens exclus de la charge ; noms de layouts publics non modérés (texte échappé à l'affichage, limité à 100 caractères).

## Actions prod
Une migration par lot (jouée par `bin/deploy`), aucune commande console.
