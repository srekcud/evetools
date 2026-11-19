# Nuit 2026-10-18 → 19 — thread 3 (liste d'achats)

Déjà fermées avant la nuit : #104, #103 (poussées par un autre thread).

## Journal
- 00:01 #117 : rouge validé (spec validée par Sylvain avant la nuit), phase verte lancée (backend + frontend) dans `evetools-wt-DI`.

- 00:20 #117 fermée et poussée (411dafb). Review : API Platform/sécurité sans bloquant ; test-skeptic insuffisant → 3 tests backend + 10 tests vitest ajoutés ; phpdoc `float|null` sur les totaux partagés. Constat sécurité préexistant (liste partagée non validée) noté en S9 dans `security-private.md`, pas d'issue publique.
- 00:35 #20 (worktree `evetools-wt-SL20`, branche `fix/shopping-list-intermediate-partial-stock`) : spec validée en autonomie ; partie stockpile sortie dans #118.
- ~07:45 #20 fermée et poussée (b7d7dc4). Review DDD : quantité passée comme runs → `IndustryTreeService::buildIntermediateTree()` ; glossaire « Produit en erreur », « Stock de départ ». test-skeptic : 6 tests ajoutés/renommés (+ #119 ouverte, coût de production). Boucle arrêtée à la demande de Sylvain.

## Décisions prises en autonomie
- #20 : produit en erreur signalé par `failedProducts` {typeId, reason} sans faire échouer la liste ; sous-arbre d'un intermédiaire en stock partiel reconstruit pour la quantité restante (runs entiers) ; stockpile reporté (#118).

## Blocages
- Session limit API atteinte vers 01:00 (reset 04:50) : le test-skeptic de #20 a échoué, relancé au matin.
