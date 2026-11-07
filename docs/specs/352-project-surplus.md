# #352 — Surplus de matières d'un projet terminé (lecture seule)

Décision de Sylvain (2026-11-07) : afficher le surplus de matières d'un projet `completed` (apporté moins requis par ligne de BOM), valorisé au prix d'apport, sans rien écrire au Stockpile et sans toucher au profit. BPC en trop et « Le déduire du coût » reportés (aucun résultat d'invention n'est enregistré). Circuit m. Maquette `industry-project-v3.html#p=27`, carte « Surplus » réduite aux matières.

## Règles

1. Seul un projet `completed` a un surplus. Un autre statut : liste vide, total `0`.
2. Apporté d'une ligne de BOM = somme des `quantity` des apports `ContributionType::Material` au statut `Approved` rattachés à cette ligne (tous participants, apports dérivés de l'achat compris). Les apports `Pending` ou `Rejected`, les autres types (`job_install`, `bpc`, `line_rental`) et les apports sans ligne n'y entrent pas.
3. Surplus d'une ligne = apporté − `requiredQuantity`. Une ligne dont le surplus est nul ou négatif n'est pas listée.
4. Prix d'apport d'une ligne = somme des `estimatedValue` de ses apports retenus (règle 2) / apporté. Valeur du surplus = surplus × prix d'apport. Un seul `estimatedValue` absent sur la ligne : prix d'apport et valeur `null` (inconnus), jamais 0.
5. Total = somme des valeurs des lignes listées ; `null` dès qu'une valeur de ligne est `null` ; `0` sans ligne.
6. Lignes triées par nom de type croissant, puis `typeId`.
7. Lecture seule : aucune écriture (ni `IndustryStockpileTarget`, ni apport, ni ligne de BOM) ; la répartition et le profit du projet restent ceux de `ProjectDistributionService`.
8. API : `GET /industry/projects/{projectId}/surplus`, `assertAcceptedMember` (403 sinon, 404 projet inconnu). Ressource `ProjectSurplus` : `id` (projet), `totalValue` (`float|null`), `lines`. Ligne : `lineId`, `typeId`, `typeName`, `requiredQuantity`, `contributedQuantity`, `surplusQuantity`, `unitPrice` (`float|null`), `surplusValue` (`float|null`). Clés nulles conservées (`skip_null_values: false`).
9. Front : carte « Surplus de matières » (`ProjectSurplus.vue`, store `surplus.ts`) rendue dans l'onglet Matériaux (`bom`) sous le tableau des matières, seulement quand le projet est `completed`. Elle liste type, requis, apporté, surplus, prix d'apport, valeur, plus le total ; un `null` s'affiche « inconnu », jamais 0 ; état vide explicite (« aucun surplus ») ; erreur de chargement visible ; mention « lecture seule : rien n'est versé au Stockpile, le profit n'est pas modifié ». Chaînes i18n en et fr.

## Exemples chiffrés

| Ligne | Requis | Apports approuvés (quantité, valeur) | Surplus | Prix d'apport | Valeur |
|---|---|---|---|---|---|
| Tritanium | 12 000 | 12 500 à 4,25 ISK/u = 53 125 | 500 | 4,25 | 2 125 |
| Morphite | 150 | 100 (1 850 000) + 60 (1 110 000) | 10 | 18 500 | 185 000 |
| Nocxium | 600 | 600 | 0 | | non listée |
| Pyerite | 1 000 | 900 | −100 | | non listée |
| Isogen | 200 | 250, valeur absente | 50 | `null` | `null` (total `null`) |

## Cas limites

- Apport `Pending` de 5 000 sur une ligne comblée : sans effet.
- Deux participants apportent sur la même ligne : quantités et valeurs additionnées, prix d'apport moyen pondéré.
- Apport matériau sur une ligne de job (composant acheté) : traité comme les autres lignes.

## Hors périmètre

BPC en trop, bouton « Ajouter au Stockpile », « Le déduire du coût », surplus de réaction (déjà dans `ProjectReactionSteps`), valorisation au prix Jita du jour.

## Décisions ouvertes

Aucune : périmètre fixé par Sylvain le 2026-11-07.
