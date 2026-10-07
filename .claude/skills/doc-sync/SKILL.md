---
name: doc-sync
description: Compare la documentation au code réel (CLAUDE.md, README, skills de référence, docs/) et corrige les écarts ; propose un diff pour CLAUDE.md sans le modifier.
disable-model-invocation: true
argument-hint: [périmètre] (défaut : tout)
---

Périmètre : $ARGUMENTS (si vide : toute la documentation).

Une doc fausse trompe les agents comme les contributeurs. Ce workflow la remet en accord avec le code.

1. Délègue à l'agent `doc-writer` la vérification de chaque affirmation factuelle de la doc du périmètre contre le code. En priorité :
   - `CLAUDE.md` : stack et versions (`composer.json`, `frontend/package.json`), table du scheduler (`src/Scheduler/SyncScheduler.php`, `src/Service/Admin/SyncTracker.php`), types de sync Mercure (`MercurePublisherService::getTopicsForUser()`), conventions API Platform, « Points en suspens », roadmap ;
   - `README.md` : stack, commandes `make`, arborescence `src/`, endpoints cités ;
   - `.claude/skills/esi-api/SKILL.md` : endpoints, pagination, gestion du 420 et du 429, refresh des tokens ;
   - `docs/glossary.md` : symboles cités (classes, méthodes, propriétés) ;
   - `docs/quality/baseline.md` : seulement signaler si les chiffres sont périmés (ne pas les remesurer ici).
2. Chaque écart est corrigé directement dans le fichier concerné, **sauf `CLAUDE.md`** : pour lui, `doc-writer` produit un diff unifié dans son rapport.
3. Présente à Sylvain :
   - la liste des écarts corrigés, avec la preuve `fichier:ligne` ;
   - le diff proposé pour `CLAUDE.md` ;
   - les écarts qui révèlent un bug de code plutôt qu'une erreur de doc, comme propositions d'issues.
4. Ne commite pas sans son accord.
