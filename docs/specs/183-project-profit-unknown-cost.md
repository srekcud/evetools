# Spec #183 — Profit d'un projet : un coût inconnu rend le profit inconnu

## Contexte

`IndustryResourceMapper::projectToResource()` (`:149-168`) calcule `totalCost`, `profit` et `profitPercent` d'une ligne de projet avec les valeurs saisies, sinon estimées, et remplace par `0.0` un coût matériaux, un coût des jobs ou une taxe dont ni la valeur saisie ni l'estimation n'existe. Le profit est alors surestimé et présenté comme connu, contre la règle R3 de la spec #21 (une estimation échouée reste inconnue). `ProjectResource` déclare déjà ces trois champs nullables et le front affiche « - » pour un profit null.

## Règles métier

- **R1 — Coûts métier.** Coût matériaux effectif = saisi, sinon estimé, sinon **inconnu**. Coût des jobs effectif = coût des jobs réels s'il est > 0 (règle actuelle), sinon estimé, sinon **inconnu**. Taxe effective = saisie, sinon estimée, sinon **inconnue**.
- **R2 — Saisies optionnelles.** Coût du BPO et coût de transport non saisis valent 0 (saisies utilisateur facultatives, comportement inchangé).
- **R3 — Propagation.** Un coût effectif inconnu rend `totalCost` null. Si un prix de vente effectif existe et que le projet n'est pas à usage personnel, `profit` et `profitPercent` sont alors null (jamais le prix de vente moins un coût partiel).
- **R4 — Inchangé.** Projet sans prix de vente effectif ou à usage personnel : `profit` / `profitPercent` = valeurs de l'entité (comportement actuel). Les totaux de profit (liste, dashboard) ne changent pas : #181, hors lot.

## Exemples chiffrés

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | Prix estimé 100 000 ; matériaux saisi null et estimé null ; jobs estimés 5 000 ; taxe estimée 3 000 | `totalCost` null, `profit` null, `profitPercent` null (profit 92 000) |
| E2 | Matériaux estimé 50 000, jobs réels 0 et estimés null, taxe 3 000, prix 100 000 | null partout (profit 47 000) |
| E3 | Matériaux 50 000, jobs 5 000, taxe saisie null et estimée null, prix 100 000 | null partout (profit 45 000) |
| E4 | Tout connu : BPO null, transport null, matériaux 50 000, jobs 5 000, taxe 3 000, prix 100 000 | `totalCost` 58 000, `profit` 42 000, `profitPercent` 72,413793… (inchangé) |
| E5 | Jobs réels 4 000 (> 0) et estimés null | jobs = 4 000, connu (inchangé) |
| E6 | Matériaux inconnus, sans prix de vente effectif | `totalCost` null ; `profit` = profit de l'entité (null sans prix saisi) |
| E7 | Matériaux inconnus, projet à usage personnel avec prix | `totalCost` null ; `profit` = profit de l'entité |

## Hors périmètre

Unification des totaux de profit (#181), calcul du profit de l'entité (`IndustryProject::getProfit`), Buy vs Build, sync des jobs.

## Glossaire

Termes existants : **Coût inconnu**. Aucun ajout.
