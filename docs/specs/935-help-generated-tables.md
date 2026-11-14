# Spec #935 — Aide : tables des scopes ESI et des fréquences de sync générées depuis le code

## Contexte

La page Aide (`/help`, #934) lit des fichiers `frontend/src/help/{fr,en}/<section>/<slug>.md`. La section `data` est vide. Les scopes demandés (`AuthenticationService::getRequiredScopes()`) et les tâches planifiées (`SyncScheduler::getSchedule()`) changent avec le code : deux tables recopiées à la main dériveraient. La page narrative « Données, fraîcheur et vie privée » (#950) viendra ensuite ; #935 livre les deux tables comme pages de la section `data`.

## Règles

- **R1 — Commande.** `app:help:generate-tables` écrit quatre fichiers dans `<dossier de sortie>/{fr,en}/data/` : `esi-scopes.md` et `sync-frequencies.md`. Dossier de sortie par défaut : `frontend/src/help` du projet ; option `--output-dir` pour le changer. Les dossiers manquants sont créés. Sortie 0 et une ligne par fichier écrit.
- **R2 — Contenu d'un fichier.** Titre de niveau 1, une phrase d'introduction qui dit que la table est générée par la commande et ne se modifie pas à la main, une ligne vide, la table markdown (en-tête, séparateur `| --- | --- |`, une ligne par entrée), un saut de ligne final. Un `|` dans un texte est échappé `\|`. Le rendu est déterministe (deux appels, même contenu).
- **R3 — Table des scopes.** Titre FR « Scopes ESI », EN « ESI scopes ». Colonnes `Scope` / `Usage` (EN `Scope` / `Used for`). Une ligne par scope de `getRequiredScopes()`, dans l'ordre du code, scope entre accents graves, puis sa description dans la langue.
- **R4 — Table des synchronisations.** Titre FR « Fréquences de synchronisation », EN « Sync frequencies ». Colonnes `Donnée` / `Fréquence` (EN `Data` / `Frequency`). Une ligne par message de `SyncScheduler::getSchedule()` (libellé de la description de sa classe de message), triées par intervalle croissant puis par libellé, puis les synchronisations à la demande (assets) avec la fréquence « À la demande » / « On demand ».
- **R5 — Intervalle.** Lu sur le trigger de chaque message planifié, en secondes : multiple de 86 400 → `N jour(s)` (EN `N day(s)`), sinon multiple de 3 600 → `N h`, sinon multiple de 60 → `N min`. Autre valeur : exception explicite, jamais d'arrondi.
- **R6 — Descriptions.** `HelpDescriptions` porte, par scope et par classe de message planifié, une description FR et une EN non vides. Une description absente ou vide lève `HelpDescriptionMissing` (message : la clé et la langue). Une description pour un scope retiré du code ou un message qui n'est plus planifié fait échouer le test (aucune description orpheline).
- **R7 — Garde de dérive.** Un test compare le rendu courant aux fichiers `frontend/src/help/{fr,en}/data/*.md` versionnés et échoue s'ils diffèrent (message : relancer `app:help:generate-tables`). Les fichiers générés sont donc committés.
- **R8 — Aide.** Le catalogue (`helpCatalog`) liste les deux pages dans la section `data` en FR et en EN, avec les titres de R3 et R4.

## Exemples

| # | Entrée | Attendu |
|---|---|---|
| E1 | intervalle 300 s | `5 min` |
| E2 | 1 800 s, 3 600 s, 7 200 s | `30 min`, `1 h`, `2 h` |
| E3 | 43 200 s ; 86 400 s ; 172 800 s | `12 h` ; `1 jour` (EN `1 day`) ; `2 jours` (EN `2 days`) |
| E4 | 90 s | exception (ni jour, ni heure, ni minute entière) |
| E5 | scope `esi-assets.read_assets.v1` | ligne ``\| `esi-assets.read_assets.v1` \| <description FR> \|`` |
| E6 | `PurgeOldNotifications` (1 jour) et `SyncWalletTransactions` (5 min) | le portefeuille (5 min) est avant la purge (1 jour) dans la table |

## Cas limites

- Aucun scope ou aucune tâche planifiée : table avec l'en-tête seul, jamais d'erreur (le test de couverture garde le cas réel).
- Une description contenant un retour à la ligne : exception de format (une ligne de table = une ligne de fichier).

## Hors périmètre

- La page narrative #950, le texte sur la vie privée et les purges.
- Un contrôle CI de la commande (le test R7 suffit).
- Les scopes par module (qui sert à quoi pour un utilisateur donné).
- Retirer ou renommer un scope ou une tâche existante.

## Décisions prises en autonomie

- Les tables sont deux pages de la section `data` plutôt qu'un fragment inclus : la page #950 les liera, `helpCatalog` ne gère pas d'inclusion.
- Les descriptions vivent en PHP (`src/Service/Help/HelpDescriptions.php`), à côté du code qu'elles décrivent ; pas de fichier de traduction séparé.
