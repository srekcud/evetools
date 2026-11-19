# Spec privée I3 — vérification par container et divisions corp (2026-10-22)

Ne jamais copier dans `docs/specs/` (dépôt public). Triage : `.claude/tasks/security-i3-triage-2026-10-22.md`.

## Règles
- R1 : `GroupIndustryContainerService` ne lit que les assets corpo visibles selon la **même règle** que `CorporationAssetsProvider` (liste blanche des divisions fixée par un directeur, contenus de containers rattachés à la division de leur container racine depuis `28f9220`/`8a5408d`). Réutiliser la règle existante, pas une copie.
- R2 : aucune configuration de visibilité pour la corporation → aucun asset lisible : tous les matériaux sortent sans quantité de container (même statut que « container introuvable »).
- R3 : un container hors des divisions autorisées (division non cochée, livraisons, fourrière, tout flag hors hangar) est traité comme introuvable : `containerQuantity === 0`, jamais la quantité réelle.
- R4 : un container dans une division autorisée continue de fonctionner comme aujourd'hui (garde-fou).

## Exemples
- E1 : liste blanche [1] ; container « Build » (itemId 100) en CorpSAG2 ; 1000 Tritanium locationId 100 ; BOM 500 Tritanium → containerQuantity 0, pas `verified`.
- E2 : même chose sans aucune configuration de visibilité → containerQuantity 0.
- E3 : liste blanche [2], même container en CorpSAG2 → containerQuantity 1000, `verified` (garde-fou).
- E4 : container en flag de livraison (`CorpDeliveries`) avec liste blanche [1..7] → containerQuantity 0.

## Hors périmètre
Containers imbriqués et trois états confondus (issue publique séparée), N+1.
