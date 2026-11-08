# Spec #314 (suite) — Aide à la vente : rattachement à une opération

**Statut : validée en autonomie en mode nuit le 2026-11-08, à relire par Sylvain.** Complète `docs/specs/314-ore-sale-helper.md` (version autonome, dans `main`) maintenant que #263 et #264 sont livrés. Circuit passé de `s` à `m` (plus de 5 fichiers de production), sans sous-agent.

## Contexte

L'issue place « Vendre mon minerai » après l'opération, sur ce que le joueur a miné pendant l'opération. La maquette (`grouped-mining-v4.html`, `renderSaleCard()`, `saleRows()`) prend les quantités minées par les personnages du joueur dans l'opération. Le ledger de l'opération donne ces quantités brutes par type (`MinedQuantityCalculator::ofParticipant()`, corrections incluses). Le minerai se vend compressé : 100 unités brutes donnent 1 unité compressée pour les minerais (portion 100), 1 pour 1 pour la glace et les gaz (portion 1).

## Règles

- **O1 — Route.** `GET /api/mining-operations/{operationId}/ore-sale` (`ROLE_USER`, `uriVariables` en `Link` vers `MiningOperationResource`), paramètre `structureId` optionnel (mêmes règles que `/mining/ore-sale`, 400 sinon). Même ressource `OreSaleResource` : `operationId` (nul pour la route autonome), `structureId`, `rows`, `totals`, `unconvertedTypes`. La route autonome ne change pas.
- **O2 — Accès.** Participant accepté ou propriétaire (`MiningOperationAccess::assertCanParticipate`, 403 sinon). Une opération inconnue ou un identifiant invalide : 404. Seules les quantités du participant connecté sont lues, jamais celles d'un autre participant.
- **O3 — Quantités connues.** Participant `measuring` ou `unknown` : 409 « The mined quantities of this operation are not known yet », jamais une réponse vide ou à 0.
- **O4 — Conversion brut vers compressé** (`MinedOreCompressedQuantities::of`). Pour chaque type miné de quantité > 0 : variante `Compressed <nom>` du SDE ; quantité compressée = `intdiv(brut, portionBrute) × portionCompressée`. Un lot incomplet est laissé de côté ; une quantité compressée nulle écarte le type sans le signaler. Type brut absent du SDE, sans variante compressée ou sans portion connue (nulle ou ≤ 0) : listé dans `unconvertedTypes` (`typeId`, `typeName` nul si absent du SDE), jamais valorisé ni compté.
- **O5 — Valorisation.** Les quantités compressées passent par la même chaîne que la version autonome (`OreSaleService::buildFromQuantities`, S1 à S5 inchangés : prix Jita et structure, meilleur prix, volume, totaux, inconnu = `null`). `OreSaleService::build` lit les actifs puis délègue à la même méthode.
- **O6 — Front.** `OreSalePanel` reçoit `operationId` optionnel : appel de la route de l'opération, sous-titre propre, message « quantités pas encore connues » sur 409 (distinct de l'erreur de chargement), note listant les `unconvertedTypes`. Le panneau s'affiche dans le détail d'une opération à l'état Clôture, Parts ou Terminée pour un participant. Le résumé à copier (S7) est inchangé.

## Exemples chiffrés

Veldspar (portion 100) → Compressed Veldspar (portion 1), glace Clear Icicle (portion 1) → Compressed Clear Icicle (portion 1).

| # | Cas | Attendu |
|---|---|---|
| Q1 | 20 000 Veldspar minés | 200 Compressed Veldspar |
| Q2 | 250 Veldspar | 2 Compressed Veldspar |
| Q3 | 50 Veldspar | type écarté, pas dans `unconvertedTypes` |
| Q4 | 5 Clear Icicle | 5 Compressed Clear Icicle |
| Q5 | type sans variante compressée | dans `unconvertedTypes`, absent des quantités |
| Q6 | portion brute nulle | dans `unconvertedTypes` |
| Q7 | participant `measuring` ou `unknown` | 409 |
| Q8 | utilisateur étranger à l'opération | 403 |
