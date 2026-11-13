# #934 — Route publique `/help`, pages FR et EN

Circuit `circuit:m`, mode nuit. Issue : feat(help): public /help route rendering the FR and EN help pages. Plan : `.claude/tasks/help-center-plan-2026-11-13.md`.

## Contexte

Le guide utilisateur existe en Markdown (`docs/user/{fr,en}/`, une page : structures industrielles) mais l'app ne l'affiche pas. Le build prod (`proxy/Dockerfile`) ne copie que `frontend/` : le contenu déménage dans `frontend/src/help/`. Contenu du dépôt, jamais d'un utilisateur : pas de nettoyage HTML.

## Règles

1. **Une seule route** `/help` (nom `help`, `meta: { requiresAuth: false }`, chargée en lazy). Aucune connexion requise : un visiteur non authentifié n'est pas redirigé vers `login`. La langue est celle de l'i18n (`useI18n().locale`), pas de segment de langue dans l'URL.
2. **Arborescence du contenu** : `frontend/src/help/{fr,en}/<section>/<slug>.md`. Sections, dans cet ordre : `faq`, `playbooks`, `calculations`, `data`, `limits`. Le guide existant devient `playbooks/industry-structures.md` dans les deux langues (le fichier FR est renommé `structures-industrielles.md` → `industry-structures.md` pour que le slug soit identique ; `git mv`, historique conservé).
3. **Catalogue** (`frontend/src/help/helpCatalog.ts`) : fonction pure `buildHelpCatalog(files)` qui reçoit un objet `{ chemin: texte }` (forme de `import.meta.glob('./{fr,en}/*/*.md', { query: '?raw', import: 'default', eager: true })`) et renvoie, pour chaque langue et chaque section de `HELP_SECTIONS`, la liste des pages `{ section, slug, title, source }`. Le titre est le premier titre `# ` du fichier. L'export `helpCatalog` est construit à l'import à partir des vrais fichiers.
4. **Parité obligatoire** : `buildHelpCatalog` lève `HelpCatalogError` (message qui nomme le fichier fautif) si
   - une page `<section>/<slug>` existe dans une langue et pas dans l'autre (dans les deux sens) ;
   - la section n'est pas dans `HELP_SECTIONS` ;
   - le fichier n'a pas de titre `# ` ;
   - le chemin n'est pas de la forme `./<fr|en>/<section>/<slug>.md`.
   Pas de repli silencieux sur l'autre langue : le test sur les vrais fichiers fait échouer la CI (et le build, car le catalogue est évalué à l'import de la vue).
5. **Rendu** (`renderHelpMarkdown(source): string`, `marked` ajouté aux dépendances, aucune config) : Markdown GFM vers HTML ; chaque titre `h1`–`h6` reçoit un `id` d'ancre : texte en minuscules, accents retirés, tout caractère non alphanumérique remplacé par `-`, tirets de bord retirés ; un doublon dans la même page reçoit `-2`, `-3`… Pas de `sanitize`.
6. **Vue** `views/Help.vue`, page autonome sur le modèle de `Legal.vue` (en-tête collant, lien de retour vers `/`, `max-w-4xl`, palette HUD slate/cyan). La page affichée est choisie par la query `?page=<section>/<slug>` ; les ancres de titres utilisent le hash (`/help?page=playbooks/industry-structures#add-a-structure`).
   - **Sans `page`** : index. Les cinq sections dans l'ordre de `HELP_SECTIONS`, chacune avec son titre (clé i18n `help.sections.<section>.title`) et la liste de ses pages (titre de la page, lien vers `?page=`). Une section sans page affiche le texte `help.emptySection`.
   - **Avec `page` valide** : fil d'Ariane (Aide › titre de section › titre de page, « Aide » ramène à l'index) et article HTML rendu. Le hash de l'URL fait défiler jusqu'au titre à l'affichage.
   - **Avec `page` inconnue** : message `help.pageNotFound` et lien vers l'index (pas d'erreur, pas de repli sur l'index muet).
   - Changer la langue de l'i18n met la vue à jour sans recharger : mêmes slugs, titres et contenus de l'autre langue.
7. **Lien dans le menu** : dans le pied du menu latéral de `MainLayout` (à côté du lien des notes de version), un `RouterLink` vers `/help` libellé `nav.help` (FR « Aide », EN « Help »). Il n'entre pas dans `allNavEntries` (palette de commandes et onglets de module inchangés). Pas de bouton « ? » dans les modules.
8. **Déménagement et références** : `git mv docs/user/{fr,en}/*` vers `frontend/src/help/{fr,en}/playbooks/` (voir règle 2) ; `docs/user/` n'existe plus ; `.claude/agents/doc-writer.md` (ligne « guide utilisateur ») pointe vers `frontend/src/help/` avec la règle section/slug et la parité FR/EN ; `docs/specs/145-install-cost-rate-bounds.md` : les deux références `docs/user/...` pointent vers les nouveaux chemins. Aucune autre référence à `docs/user` ne subsiste (`grep -rn "docs/user"` vide hors historique git).

## Exemples (ce seront les tests rouges)

| Entrée | Sortie attendue |
|---|---|
| `{ './fr/playbooks/a.md': '# Titre A', './en/playbooks/a.md': '# Title A' }` | catalogue : `fr.playbooks = [{slug:'a', title:'Titre A'}]`, `en.playbooks = [{slug:'a', title:'Title A'}]`, autres sections vides |
| `./fr/faq/q.md` sans `./en/faq/q.md` | `HelpCatalogError` nommant `faq/q` |
| `./en/limits/x.md` sans `./fr/limits/x.md` | `HelpCatalogError` nommant `limits/x` |
| `./fr/unknown/a.md` et `./en/unknown/a.md` | `HelpCatalogError` (section inconnue) |
| `./fr/faq/q.md` = `texte sans titre` (+ EN valide) | `HelpCatalogError` (titre manquant) |
| `# Ajouter une structure` | `<h1 id="ajouter-une-structure">` |
| `## Prix & marché (Jita)` | `<h2 id="prix-marche-jita">` |
| deux `## Notes` | `id="notes"` puis `id="notes-2"` |
| tableau GFM `\| a \| b \|` | `<table>` |
| `/help` non authentifié | route résolue sur `help`, pas de redirection vers `login` |
| `/help` en `fr` | les 5 titres de section FR dans l'ordre, la page « Configurer ses structures industrielles » listée sous Playbooks |
| même vue en `en` | « Configuring your industry structures » |
| `/help?page=playbooks/industry-structures` | un `h1` = titre de la page de la langue courante, fil d'Ariane |
| `/help?page=playbooks/nope` | texte `help.pageNotFound` + lien vers `/help` |
| section sans page (ex. `faq` avant #936) | texte `help.emptySection` sous le titre de section |
| `MainLayout` sur `/dashboard` | le pied du menu contient un lien `href="/help"` libellé `nav.help` ; le menu principal garde ses entrées inchangées |

## Cas limites

- Vrais fichiers : le test de parité tourne sur `helpCatalog` réel (une page aujourd'hui) ; il échoue dès qu'un fichier manque dans une langue.
- Une page `<section>/<slug>` dont le titre change d'une langue à l'autre est normale ; seul le slug (nom de fichier) doit coïncider.
- Slug dans la query contenant `..` ou une section inconnue : traité comme page inconnue (aucune lecture de fichier hors catalogue).
- Titre vide après retrait des accents (`# ???`) : l'ancre est `section` (repli fixe, pas de `id=""`).

## Accès et fraîcheur

Route publique, contenu statique du bundle : aucun endpoint, aucune donnée utilisateur, aucun appel ESI. Pas de Mercure (aucune action asynchrone).

## Tests existants contredits ou modifiés

Aucun attendu. À surveiller : tests de `MainLayout` qui énumèrent les liens du menu (`MainLayoutNavigation.spec.ts`) et tests i18n de parité de clés FR/EN (les nouvelles clés `help.*` et `nav.help` existent dans les deux fichiers).

## Hors périmètre

Contenu des pages (#936 et suivantes), génération des tableaux scopes et fréquences (#935), bouton « ? » contextuel, recherche dans l'aide, sommaire latéral, nettoyage HTML, ajout de pages hors `docs/user` existantes.

## Décisions prises en autonomie (mode nuit)

- Sections par sous-dossier (`<section>/<slug>.md`) plutôt que front matter : rien à parser, la structure du dépôt est la source.
- Page choisie par `?page=` plutôt que par segment de chemin : respecte « une seule route » et laisse le hash aux ancres.
- Renommage du fichier FR pour l'identité du slug : alternative écartée, une table de correspondance FR/EN.
- Nouvelle dépendance `marked` : décidée par Sylvain dans l'issue (technicité niveau 3 : nouvelle dépendance, PR sans fusion par le thread).

## Glossaire

Aucun terme métier ajouté (page d'aide, section d'aide : vocabulaire d'interface).
