# Spec #220 — Navigation sur écran étroit : tiroir de navigation

## Contexte

`MainLayout.vue` affiche une barre latérale de 256 px (`<aside class="w-64">`) quelle que soit la largeur de l'écran, sans aucun point de rupture. L'en-tête garde `px-8 py-4` et la zone de contenu `p-8`. Mesuré dans le navigateur (données de dev) :

| Écran | Largeur | Barre latérale | Zone de contenu | Largeur rendue du contenu |
|---|---|---|---|---|
| Dashboard | 390 px | 256 px | 134 px | 573 px |
| Industry | 390 px | 256 px | 134 px | 780 px |
| PI | 390 px | 256 px | 134 px | 507 px |
| Industry | 768 px | 256 px | 512 px | 780 px |

Sur téléphone, le contenu dispose de 134 px et défile horizontalement ; à 768 px, Industry déborde encore de 268 px. Or plusieurs fonctions servent hors du jeu (fin de jobs, expiration des extracteurs PI, notifications).

L'état replié des groupes de navigation est déjà persisté dans `localStorage` sous `evetools_nav_collapsed` (objet `{ [groupId]: true }`, seul groupe aujourd'hui : `production`). Cette clé ne concerne pas la barre latérale elle-même : il n'existe aujourd'hui aucun état « barre latérale repliée ».

L'issue #232 (même lot, en parallèle) remplace les entrées de navigation `<button>` par des `RouterLink`. Les règles ci-dessous ne dépendent pas de l'élément utilisé : elles parlent d'« entrée de navigation ».

## Règles

- **R1 — Point de rupture de navigation.** L'écran est **large** quand `(min-width: 1024px)` est vrai (`lg` de Tailwind, borne incluse), **étroit** sinon. La valeur 1024 est la proposition de l'issue, à confirmer (Q1).
- **R2 — Écran large : comportement actuel.** La barre latérale de 256 px est affichée en permanence, sans bouton de menu ni tiroir. Rien ne change par rapport à aujourd'hui.
- **R3 — Écran étroit : barre latérale masquée.** La barre latérale persistante n'est pas rendue. L'en-tête porte un **bouton de menu** (burger), à gauche du titre, avec un nom accessible traduit (FR/EN), `aria-controls` vers le tiroir et `aria-expanded` reflétant son état. Le contenu occupe toute la largeur. Sur tablette (768 à 1023 px), même comportement que sur téléphone, sous réserve de Q2.
- **R4 — Tiroir de navigation.** Le bouton de menu ouvre un **tiroir** par-dessus le contenu, depuis la gauche, avec un fond assombri. Le tiroir contient exactement la navigation de la barre latérale : logo, entrées et groupes filtrés par `evetools_hidden_modules`, entrée Administration pour un admin, bloc personnage (paramètres, Characters, déconnexion, notes de version). Il porte `role="dialog"`, `aria-modal="true"` et un bouton de fermeture.
- **R5 — Le tiroir est fermé au chargement.** Son état ouvert n'est jamais persisté : chaque chargement en écran étroit démarre tiroir fermé.
- **R6 — Fermeture du tiroir.** Le tiroir se ferme sur :
  - un clic simple (sans Ctrl, Meta, Maj ni bouton du milieu) sur une entrée de navigation, **y compris l'entrée de la route courante** ;
  - tout changement de route, quelle qu'en soit l'origine (lien, bouton Characters, retour arrière du navigateur, `router.push` programmatique) ;
  - un clic sur le fond assombri ;
  - le bouton de fermeture ;
  - la touche Échap ;
  - l'ouverture de la modale des paramètres depuis le bloc personnage (la modale remplace le tiroir).
- **R7 — Un groupe de navigation ne ferme pas le tiroir.** Un clic sur l'en-tête d'un groupe (`production`) le replie ou le déplie, comme dans la barre latérale, et le tiroir reste ouvert.
- **R8 — `evetools_nav_collapsed` inchangé.** Même clé, même format, partagés entre barre latérale et tiroir : replier un groupe dans le tiroir le replie aussi dans la barre latérale, et inversement. Un groupe dont un enfant est la route courante reste déplié (règle actuelle de `isGroupExpanded()`). Valeur illisible : tous les groupes dépliés (comportement actuel).
- **R9 — Focus et clavier.** À l'ouverture, le focus passe dans le tiroir (bouton de fermeture). Tant que le tiroir est ouvert, le reste de la page (en-tête et contenu) est `inert`, ce qui garde le focus dans le tiroir. À la fermeture par Échap, par le bouton de fermeture ou par le fond, le focus revient au bouton de menu. À la fermeture par navigation, le focus suit le comportement normal de la page.
- **R10 — Changement de largeur.** Passer d'étroit à large ferme le tiroir et retire `inert` ; repasser en étroit affiche le tiroir fermé. Passer de large à étroit affiche le bouton de menu, tiroir fermé.
- **R11 — Marges réduites sous `md`.** Sous 768 px, l'en-tête passe de `px-8` à `px-4` et la zone de contenu de `p-8` à `p-4`. Au-dessus, inchangé.
- **R12 — En-tête sous `md`.** L'en-tête tient sur une ligne sans défilement horizontal à 375 px : bouton de menu, titre (tronqué si trop long), cloche de notifications. Les éléments masqués ou réduits sous `md` (sous-titre « Bon retour », badges alliance et corporation, badge ESI) sont l'objet de Q3.

## Exemples chiffrés (tests rouges Vitest)

Harnais : celui de `frontend/tests/layouts/MainLayoutNavigation.spec.ts` (#232), avec la largeur fixée par `window.happyDOM.setViewport({ width })` avant le montage, et changée après le montage pour E14 et E15 (happy-dom émet `change` sur les `MediaQueryList` au redimensionnement). En happy-dom, les classes Tailwind responsive n'ont aucun effet : l'état testé doit être porté par le rendu (présence des éléments, `aria-expanded`, `inert`), pas par du CSS seul.

« Barre latérale » = la navigation persistante ; « bouton menu » = le bouton de R3 ; « tiroir » = l'élément `role="dialog"` de R4.

| # | Largeur | Route | Action | Barre latérale | Bouton menu | Tiroir | Autre attendu |
|---|---|---|---|---|---|---|---|
| E1 | 1440 | /dashboard | montage | visible | absent | absent | |
| E2 | 1024 | /dashboard | montage | visible | absent | absent | borne incluse |
| E3 | 1023 | /dashboard | montage | absente | présent, `aria-expanded="false"` | fermé | |
| E4 | 768 | /dashboard | montage | absente | présent, `false` | fermé | |
| E5 | 375 | /dashboard | montage | absente | présent, `false` | fermé | |
| E6 | 375 | /dashboard | clic bouton menu | absente | `aria-expanded="true"` | ouvert, contient Dashboard, Revenue, Industry, Market, Inventory | en-tête et contenu `inert`, focus sur le bouton de fermeture |
| E7 | 375 | /dashboard | ouvert, clic entrée Industry | absente | `false` | fermé | route `/industry` |
| E8 | 375 | /dashboard | ouvert, clic entrée Dashboard (route courante) | absente | `false` | fermé | route `/dashboard` |
| E9 | 375 | /dashboard | ouvert, clic fond assombri | absente | `false` | fermé | focus sur le bouton menu, plus d'`inert` |
| E10 | 375 | /dashboard | ouvert, touche Échap | absente | `false` | fermé | focus sur le bouton menu |
| E11 | 375 | /dashboard | fermé, touche Échap | absente | `false` | fermé | route inchangée, aucune erreur |
| E12 | 375 | /dashboard | ouvert, `router.push('/market')` | absente | `false` | fermé | |
| E13 | 375 | /dashboard | ouvert, clic en-tête du groupe Production | absente | `true` | ouvert | groupe replié, `evetools_nav_collapsed` = `{"production":true}` |
| E14 | 375 → 1280 | /dashboard | ouvert, puis redimensionnement | visible | absent | absent | plus d'`inert` |
| E15 | 1280 → 800 | /dashboard | redimensionnement | absente | présent, `false` | fermé | |
| E16 | 375 | /dashboard | `evetools_nav_collapsed` = `{"production":true}`, clic bouton menu | absente | `true` | ouvert | entrées Industry, PI masquées dans le tiroir |
| E17 | 375 | /industry | `evetools_nav_collapsed` = `{"production":true}`, clic bouton menu | absente | `true` | ouvert | groupe Production déplié (enfant actif) |
| E18 | 375 | /dashboard | ouvert, clic bloc personnage (paramètres) | absente | `false` | fermé | modale des paramètres ouverte |

Vérification manuelle (Chrome, largeur fixe) à 375, 768 et 1024 px : la barre latérale ou le tiroir se comporte comme ci-dessus, et l'en-tête ne provoque aucun défilement horizontal.

## Cas limites

- **Clic modifié.** Ctrl/Meta/clic du milieu sur une entrée (nouvel onglet, possible après #232) ne ferme pas le tiroir : la route courante ne change pas.
- **Échap déjà utilisé ailleurs.** Le gestionnaire Échap du tiroir n'agit que tiroir ouvert : il ne doit rien empêcher quand le tiroir est fermé (ex. `MarketSearch.vue` écoute aussi `keydown` sur `window`).
- **Bannière de rate limit.** Quand la bannière ESI (fixe, `z-50`) est affichée, elle reste visible au-dessus du tiroir et du fond.
- **Défilement.** Le défilement est porté par `<main>` (`overflow-auto`), pas par `body` ; le tiroir et le fond sont fixes et hors de `<main>`, donc le contenu ne défile pas sous le tiroir.
- **Modules masqués.** Un module masqué dans les paramètres est absent du tiroir comme de la barre latérale ; un groupe sans enfant visible est absent.
- **Changement de largeur pendant l'ouverture.** Couvert par R10 (E14) ; aucune transition visible n'est exigée.
- **Déconnexion depuis le tiroir.** Le flux de déconnexion actuel redirige : le changement de route ferme le tiroir.

## Hors périmètre

- **Largeur propre des vues.** Dashboard (573 px), PI (507 px) et Industry (780 px) ont une largeur rendue supérieure à l'espace disponible même sans barre latérale : 343 px à 375 px avec `p-4`, 704 px à 768 px avec `p-8`. Le critère de l'issue « pas de défilement horizontal sur Dashboard, Industry et PI à 375 et 768 px » ne peut pas être tenu par la seule mise en page : chaque vue relève d'une issue séparée. Cette spec ne garantit que l'absence de débordement dû au shell (barre latérale, en-tête, marges). Même remarque à 1024 px pour Industry avec barre latérale : 704 px disponibles pour 780 px.
- **Barre de navigation basse** (Dashboard, Industry, PI, Notifications), option évoquée par l'issue.
- **Repli manuel de la barre latérale en icônes sur écran large** (nouvel état persisté).
- **Passage des entrées en `RouterLink` et `aria-current`** : #232.
- **Échap pour fermer la modale des paramètres** : comportement inchangé.
- **Geste de balayage** (swipe) pour ouvrir ou fermer le tiroir.

## Glossaire (termes proposés, non ajoutés)

Section « Interface » à créer dans `docs/glossary.md`, après validation :

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Barre latérale** | Sidebar | Navigation principale affichée en permanence à gauche sur écran large (256 px). | `<aside>` de `MainLayout.vue` |
| **Tiroir de navigation** | Navigation drawer | Navigation principale sur écran étroit : fermée par défaut, ouverte par le bouton de menu par-dessus le contenu, refermée par navigation, fond, Échap ou bouton de fermeture (spec #220). | à créer dans `MainLayout.vue` |
| **Groupe de navigation** | Navigation group | Entrée repliable qui regroupe des écrans (Production) ; son état replié est persisté dans `evetools_nav_collapsed` et partagé entre barre latérale et tiroir. | `NavGroup`, `collapsedGroups`, `isGroupExpanded()` |
| **Point de rupture de navigation** | Navigation breakpoint | Largeur d'écran à partir de laquelle la barre latérale remplace le tiroir (1024 px, à confirmer). | à créer |

## Décisions validées (gate 🚦)

Validée le 2026-10-27 : Sylvain a retenu la recommandation de chaque question ci-dessous.

1. **Point de rupture : 1024 px (`lg`) ou 1280 px (`xl`) ?** À 1024 px avec barre latérale, Industry dispose de 704 px pour 780 px rendus ; avec `xl`, un iPad en paysage aurait le tiroir et 960 px de contenu, mais un portable en 1280 px garderait la barre latérale. *Recommandation : `lg` (1024 px), comme proposé par l'issue ; le débordement d'Industry entre 1024 et 1099 px relève de la vue (hors périmètre).*
2. **Tablette (768 à 1023 px) : tiroir comme sur téléphone, ou rail d'icônes persistant (64 px) ?** Le rail garde la navigation visible mais demande des infobulles, un traitement du groupe Production réduit à une icône, et ne laisse que 640 px de contenu à 768 px. *Recommandation : tiroir sur toute la plage étroite, un seul mode, plus simple à tester ; le rail peut venir plus tard sans casser cette spec.*
3. **En-tête sous 768 px : que garder ?** Aujourd'hui : titre et sous-titre, cloche, badge ESI (texte + nombre de joueurs), badges alliance et corporation avec nom. *Recommandation : garder bouton de menu, titre tronqué, cloche et la seule pastille de couleur ESI (libellé en infobulle) ; masquer le sous-titre et les badges alliance et corporation sous `md`.*
