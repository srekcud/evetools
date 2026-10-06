---
name: audit
description: Audit adversarial en lecture seule d'un domaine du code ; produit des propositions d'issues, aucun correctif.
disable-model-invocation: true
argument-hint: <domaine> (esi | messenger | scheduler | doctrine | security | industry | dead-code | duplication | …)
---

Domaine à auditer : $ARGUMENTS

Ce code a été écrit en grande partie par un agent, sur de nombreuses sessions. Ton rôle n'est pas de le juger « raisonnable » : **trouve où il ment**.

1. Lis `docs/glossary.md` et les ADR de `docs/adr/` pertinentes pour le domaine.
2. Délègue à l'agent `auditor`, en lecture seule. Selon le domaine, ajoute en parallèle les reviewers pertinents :
   - `esi-reviewer` pour le client ESI et les syncs ;
   - `security-reviewer` pour le scoping corpo, les voters et les secrets ;
   - `api-platform-reviewer` pour les ressources, providers et processors ;
   - `test-skeptic` pour la qualité des tests existants du domaine.
3. Cherche en priorité :
   - la dérive entre sessions (plusieurs façons de faire la même chose) ;
   - la duplication, en particulier sur les prix et les coûts ;
   - le code mort qui a l'air vivant ;
   - les tests complaisants ;
   - le code sur-défensif (`|| true`, `?? 0`, catch qui avalent) ;
   - un nom de paramètre en désaccord avec son usage réel (cf. B1 : runs et quantité).
4. Chaque constat cite `fichier:ligne` et est **vérifié dans le code**, jamais supposé. Une information vérifiable est vérifiée. Sinon, écris « inconnu » avec ce qui a été tenté.
5. Sortie : une liste priorisée de propositions d'issues. Pour chacune : titre, labels (type + module), corps anonymisé, impact utilisateur, et test qui prouverait le problème.
6. **Ne crée aucune issue et ne modifie aucun fichier.** La création se fait après accord explicite de Sylvain.
