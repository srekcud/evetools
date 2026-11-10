# Spec #367 (reliquat) — Compagnon de minage : badge de compression et bandeau

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-10, sans sous-agent.
- **Circuit** : `circuit:m`, tenu comme S (front seul, un fichier de production, sans migration ni contrôle d'accès).
- **Contexte** : PR #484 a livré le verdict par type de site, le badge et le bandeau sur la page du site et sur la page de l'opération (`docs/specs/367-mining-site-compression.md`). Il restait le compagnon (#304, livré par les PR #515 et #522), que l'issue et le commentaire du 2026-11-09 laissaient pour plus tard : « le compagnon réutilisera badge et bandeau ».

## Règles

- **R1 — Donnée.** Aucun changement d'API : le compagnon charge le site par `GET /api/mining-sites/{id}`, qui porte déjà `compression` (`MiningSite.compression`).
- **R2 — Bandeau.** `MiningCompanionPanel` affiche `MiningCompressionBanners` (un bandeau ambre par écart `noHull` / `notMounted`, puis « la valeur des parts ne change pas ») pour les tailles `compact` et `detail`. Pas en taille `bar` (une ligne). Rien sans `compression` (site privé) ni sans coque (`boostShipTypeId` `null`, aucun écart).
- **R3 — Badge.** Taille `detail` : badge « sur place » (vert), « aucun » (ambre), « inconnu » (gris) sous le nom de chaque type du tableau, même composable `useMiningCompression` que la page du site. Pas de badge sans `compression` ni sans coque. Types sans verdict dans `compression.types` : pas de badge.
- **R4 — Valeur inchangée.** Aucun montant, aucune ETA ne change.
- **R5 — Libellés.** Clés `miningOperation.boostFit.*` existantes, aucune clé nouvelle.

## Exemples (tests rouges)

Site Porpoise, scan Veldspar / Scordite : Veldspar `on_site`, Scordite `no_hull_compressor`, écarts `noHull: [moon]`, `notMounted: [ice]`.

| # | Entrée | Résultat |
|---|---|---|
| 1 | taille `detail` | badges « sur place » et « aucun » sur les lignes, deux bandeaux, mention des parts |
| 2 | taille `compact` | deux bandeaux et mention, pas de tableau donc pas de badge |
| 3 | taille `bar` | ni bandeau ni badge |
| 4 | `compression` `null` ou coque `null` | ni bandeau ni badge, quelle que soit la taille |

## Hors périmètre

Page du site et page de l'opération (livrées), valorisation, Mercure (lecture seule, aucun topic).
