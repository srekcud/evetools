# Boucle de nuit 2026-11-11 : deux chaînes

Une issue par thread, Sonnet, mode nuit, PR par issue (Sylvain relit et fusionne). Chaque thread lance le suivant de sa file en fin de tour (voir `night-chain-thread-prompt.md`). Journal : `loop-2026-11-11-night.md`.

## Chaîne A : admin backend (scheduler, Messenger, ESI, support), puis assets-sync
- [x] #637 fix(admin): queue depths and failed purge from RabbitMQ (m) : PR #676
- [x] #638 fix(scheduler): track the six missing scheduled tasks (m) : PR #678
- [x] #640 fix(admin): keys instead of French labels (s) : PR #679
- [x] #639 feat(admin): manual trigger for every tracked sync type (m) : PR #682
- [x] #645 feat(admin): shared ESI error budget and 420/429 counts (m) : PR #685
- [x] #649 feat(admin): warn on users close to the Mercure cookie limit (s) : PR #687
- [x] #648 feat(admin): characters with tokens or scopes to repair (m) : PR #690 (partielle : points 3 et 4 bloqués sur #647 et #642, base fix/night-649)
- [x] #647 feat(admin): user support sheet (l) : PR #693 (base fix/night-648)
- [x] #654 feat(admin): read-only list of admin characters (s) : PR #694 (base fix/night-647)
- [x] #674 fix(admin): recover a sync stuck in running state (m) : PR #695 (partielle : enquête Public Contracts sans logs prod, base fix/night-654)
- [x] #666 fix(assets): sync corporation assets with a character whose token is valid (s) : PR #696
- [x] #667 fix(assets): report a corporation assets sync that cannot run (s) : PR #697 (base fix/night-666)
- [x] #668 fix(assets): notify the user who triggered a corporation assets sync (m) : PR #698 (base fix/night-667)
- [x] #670 fix(assets): stop dropping container names when ESI rejects a names batch (m) : PR #699
- [x] #671 fix(assets): find items stored inside containers when searching (m) : PR #700
- [x] #672 fix(admin): track user-triggered corporation assets syncs in the sync health table (s, après #666-#668) : PR https://github.com/srekcud/evetools/pull/701 (SHA ad996411), base fix/night-668

## Chaîne B : market, front admin
- [x] #631 chore(frontend): lint error on main + frontend lint in CI (s) : PR #677
- [x] #632 refactor(market): one mapping for MarketAlertResource (m) : PR #680
- [x] #636 fix(admin): wire the three dead maintenance buttons (s) : PR #681
- [x] #641 feat(admin): confirm destructive maintenance actions (s) : PR #683 (partielle, Clear cache à trancher)
- [x] #653 feat(admin): sort sync health by severity, next run (s) : PR #684
- [x] #651 fix(admin): rework the admin charts (m) : PR #686 (partielle : activité par jour bloquée sur #650, base fix/night-640)
- [x] #642 feat(admin): global status banner (m) : PR #688 (partielle : règles #643/#645/#649 absentes de la pile, base fix/night-653)
- [x] #673 fix(admin): on-demand syncs without an interval instead of NaNmin (s) : PR #689 (base fix/night-642)
- [x] #652 feat(admin): split the admin page into tabs (m) : PR #691 (partielle : Users sans liste tokens ni fiche, #647/#648 ; base fix/night-673)
- [x] #669 fix(industry): hide the container gap line when the quantity is unknown (s) : PR #692

## Hors boucle (attendent Sylvain)
- circuit:decision : #643, #644, #646, #650, #675
- #299, #302 : suppressions de champs et commandes, accord requis
- #248 : PostgreSQL 18, infra (images, prod, CI)

## Chaîne C : stock d'intermédiaires dans la BOM (plan `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/intermediate-stock-plan.md`, à lire par chaque thread ; D1-D3 et périmètre solo tranchés par Sylvain le 2026-11-11)
- [x] #706 feat(industry): deduct intermediates already in stock from a solo project BOM (l) : PR https://github.com/srekcud/evetools/pull/710 (SHA 56787e49)
- [x] #708 feat(industry): propose the intermediates found in assets for a solo project (m) : PR https://github.com/srekcud/evetools/pull/711 (SHA 2d582f46, base fix/night-706)
- [x] #707 fix(industry): check intermediates in the project chain and container (m) : PR https://github.com/srekcud/evetools/pull/712 (SHA e7a186a4, base fix/night-706)
- [ ] #709 feat(industry): show the intermediate surplus of a completed project (s)
