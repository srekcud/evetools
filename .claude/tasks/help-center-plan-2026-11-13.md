# Centre d'aide : FAQ, playbooks, « comment c'est calculé »

Statut : plan à valider. Suite des visites guidées (prototype `docs/design/mockups/onboarding.html`, artifact « Visites guidées EVE Tools »).

## Décisions de Sylvain (2026-11-13)

- Emplacement : page **Aide** dans l'app, route publique `/help` sans connexion (comme `/legal`). Pas de site séparé.
- Contenu : guides par objectif **et** référence des calculs.
- Profondeur : formules exactes, données et fraîcheur, scopes et vie privée, limites connues.
- Langues : FR et EN dès le départ.

## Choix tranchés (2026-11-13, suite)

1. **URL** : une seule route `/help`, langue de l'i18n.
2. **Rendu** : le plus simple. Fichiers `.md` importés en texte par Vite (`import.meta.glob(..., { query: '?raw' })`), convertis par `marked` (une dépendance, aucune config). Pas de nettoyage HTML : le contenu vient du dépôt, jamais d'un utilisateur.
3. **Pas de « ? » dans les modules** pour le moment. Le bouton Visite reste le seul point d'entrée contextuel ; lien Aide dans le pied du menu.

## Conséquence : le contenu déménage dans `frontend/`

Le build prod (`proxy/Dockerfile`) ne copie que `frontend/`, et le conteneur de dev ne monte que `./frontend`. `docs/user/` est donc invisible pour Vite.
- `git mv docs/user/{fr,en}/* frontend/src/help/{fr,en}/` (un seul guide aujourd'hui : structures industrielles).
- Mettre à jour `.claude/agents/doc-writer.md` (chemin du guide utilisateur) et les deux références de `docs/specs/145-install-cost-rate-bounds.md`.

## Arborescence proposée

```
/help
├── FAQ
├── Playbooks (guides par objectif)
├── Comment c'est calculé (référence)
├── Données, fraîcheur et vie privée
└── Limites connues
```

### FAQ (premier jet, à trier)

Compte et personnages
- Pourquoi EVE Tools demande 30 scopes ESI ? Lequel sert à quoi ?
- Ajouter un alt, changer de personnage principal, que se passe-t-il quand un jeton expire ?
- Mes données sont-elles visibles par ma corporation ? Par les membres d'un projet de groupe ?
- Comment supprimer mon compte et mes données ?

Chiffres « bizarres »
- Pourquoi « inconnu » au lieu d'un prix ou d'une marge ?
- Pourquoi mon inventaire n'est pas à jour (assets synchronisés à la demande seulement) ?
- Pourquoi le coût d'un projet diffère de ce que j'ai payé en jeu ?
- Pourquoi un job terminé n'apparaît pas tout de suite (sync des jobs toutes les 30 min, cache ESI) ?

Industrie
- Comment sont choisis la structure et les bonus d'un projet ?
- ME/TE : d'où viennent les valeurs par défaut ?
- Acheter ou produire : comment la décision est calculée ?

Projets de groupe
- Qui peut rejoindre, qui approuve, que signifie « approuvé = verrouillé » ?
- Comment est valorisé un apport (prix payé, coût ESI, prix Jita du jour) ?
- Comment est réparti le revenu, quand les payouts deviennent-ils exacts ?

Marché, PI, minage, PVE : une à trois questions par module, à collecter depuis les retours Discord (agent `triager`).

### Playbooks

1. Premiers pas : connexion, alts, structures, première sync.
2. Monter un projet T2 de A à Z (analyse → projet → achats → jobs → vente).
3. Lancer un projet de groupe (propriétaire) : publier, inviter, approuver, distribuer, terminer.
4. Participer à un projet de groupe (membre) : rejoindre, apporter, suivre sa part.
5. Surveiller ses ordres de marché et reprendre la tête.
6. Tenir ses colonies PI sans arrêt d'extracteur.
7. Opération de minage et taxe lunaire.

Chaque playbook reprend les étapes de la visite guidée du module, avec captures anonymisées (aucun vrai personnage, corporation ni alliance : dépôt public).

### Comment c'est calculé

Une page par calcul : formule, source de chaque terme, exemple chiffré, cas « inconnu ». Liste à établir à partir de `docs/modules/industry.md` et du glossaire :
- coût de job (indices de coût, taxe de structure, prix ajustés), bonus structure et rigs, ME/TE ;
- frais de vente (compétences et standings du vendeur) ;
- marge (profit / coût de production) ;
- valorisation d'un apport et partage au prorata dans un projet de groupe ;
- prix pondéré, évaluation (Appraisal), valeur du minerai, taxe lunaire.

### Données, fraîcheur et vie privée

- Tableau source → fréquence, généré depuis `src/Scheduler/SyncScheduler.php` (pas recopié à la main).
- Scopes : tableau généré depuis `AuthenticationService::REQUIRED_SCOPES`, avec l'usage de chacun.
- Ce qui est stocké, partagé (corporation, projet de groupe, liste d'achats partagée) et purgé (purges quotidiennes).

### Limites connues

Liste courte, datée, reliée aux issues ouvertes. Révisée à chaque release `V0.x`.

## Méthode

- Rédaction par l'agent `doc-writer`, qui vérifie chaque affirmation dans le code. Une page = une issue, label `group:help-center`.
- FR d'abord dans la même PR, puis EN dans la même PR (jamais une langue seule en prod).
- Tableaux générés (scopes, fréquences) par une commande console ou un script, pour ne pas dériver du code.
- Revue par `test-skeptic` inutile ici ; relecture par Sylvain des pages « calculé » avant publication.

## Prochaine étape

Issues créées, voir ci-dessous. Ordre : #934, puis #935, puis les pages.

## Issues créées (2026-11-13, label `group:help-center`)

- #934 route `/help` + rendu `marked` + déménagement de `docs/user/` (circuit:m)
- #935 génération des tableaux scopes et fréquences (circuit:m)
- #936 FAQ
- #937 à #943 playbooks : premiers pas, T2 de A à Z, projet de groupe propriétaire, projet de groupe membre, ordres de marché, PI, minage et taxe lunaire
- #944 à #949 calculs : coût de job et bonus, frais de vente, marge, apports et distribution de groupe, prix pondéré et appraisal, valeur du minerai et taxe lunaire
- #950 données, fraîcheur et vie privée (dépend de #935)
- #951 limites connues
