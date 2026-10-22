# Spec #152 — Projet collaboratif terminé figé, suppression limitée, cycle de statut branché

## Contexte

Le statut d'un projet collaboratif (`GroupProjectStatus` : `draft`, `published`, `in_progress`, `selling`, `completed`) ne protège rien : seule l'adhésion le regarde (`JoinGroupProjectProcessor.php:61`). Un projet `completed` accepte encore contributions, revues, ventes et changement des frais. Le propriétaire peut supprimer le projet à tout moment, avec ses contributions et ventes en cascade (`GroupIndustryProject.php:71-87`), alors que la spec fonctionnelle prévoit des projets non annulables. Les transitions déclarées par `UpdateGroupProjectProcessor` (`published → in_progress → selling → completed`) ne sont jamais envoyées par le frontend : aucun projet n'atteint `completed`.

La cascade de `GroupIndustrySale::$recordedBy` est déjà corrigée par #53 (`GroupIndustrySale.php:49`, `onDelete: 'SET NULL'` ; figé par `AccountDeletionServiceTest`, vente conservée « Pilote supprimé »). Rien à faire ici.

## Règles métier

- **R1 — Projet terminé figé.** Sur un projet `completed`, sont refusés en 400 : soumettre une contribution, approuver ou rejeter une contribution, enregistrer une vente, modifier les frais de courtage (`brokerFeePercent`) ou la taxe de vente (`salesTaxPercent`). Le contrôle d'accès passe avant : un non-membre garde sa réponse actuelle (403/404).
- **R2 — Le reste de la modification reste permis.** Sur un projet `completed`, le nom et le nom du container restent modifiables (ils ne changent aucun montant).
- **R3 — Suppression limitée.** Le propriétaire ne peut supprimer un projet que s'il n'est pas `completed`, n'a aucune contribution `approved` et aucune vente. Sinon 400, rien n'est supprimé. Une contribution `pending` ou `rejected` ne bloque pas la suppression.
- **R4 — Cycle de statut branché.** Le propriétaire ou un admin voit dans le détail du projet un bouton qui fait passer le projet au statut suivant (`published → in_progress → selling → completed`) via `PATCH` `status`. Le passage à `completed` demande une confirmation (irréversible). Aucun bouton sur un projet `completed` ou `draft`.
- **R5 — Interface d'un projet terminé.** Sur un projet `completed`, le détail n'affiche ni le bouton de suppression ni le bouton « Contribute ». Les autres refus (revue, vente, frais) viennent du backend et s'affichent comme les erreurs actuelles.
- **R6 — Inchangé.** Transitions refusées hors de la table (`Cannot transition from "x" to "y"`, 400) ; `completedAt` posé au passage à `completed` ; seul le propriétaire supprime (403 sinon) ; l'adhésion reste limitée à `published` et `in_progress`.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Membre `accepted` soumet une contribution matériau (quantité 10) sur un projet `completed` | 201, contribution `pending` | 400, aucune contribution créée |
| E2 | Même cas sur un projet `selling` | 201 | 201 (garde-fou) |
| E3 | Propriétaire approuve une contribution `pending` sur un projet `completed` | 200, `approved`, fulfillment +10 | 400, contribution `pending`, fulfillment inchangé |
| E4 | Propriétaire rejette une contribution `pending` sur un projet `completed` | 200, `rejected` | 400, contribution `pending` |
| E5 | Propriétaire approuve sur un projet `in_progress` | 200 | 200 (garde-fou) |
| E6 | Admin enregistre une vente (5 × 1 200 000 ISK) sur un projet `completed` | 201 | 400, aucune vente persistée |
| E7 | Même vente sur un projet `selling` | 201, total 6 000 000 ISK | 201 (garde-fou) |
| E8 | Propriétaire passe `salesTaxPercent` de 3,6 à 2,0 sur un projet `completed` | 200, 2,0 | 400, taxe 3,6 inchangée |
| E9 | Propriétaire passe `brokerFeePercent` de 3,0 à 1,5 sur un projet `completed` | 200 | 400, frais 3,0 inchangés |
| E10 | Propriétaire renomme un projet `completed` en « Rorqual batch » | 200 | 200, nom changé (R2) |
| E11 | Propriétaire passe la taxe à 2,0 sur un projet `selling` | 200 | 200 (garde-fou) |
| E12 | Propriétaire supprime un projet `published` sans contribution ni vente | 204 | 204 (garde-fou) |
| E13 | Propriétaire supprime un projet `in_progress` avec 1 contribution `approved` | 204, tout supprimé | 400, projet conservé |
| E14 | Propriétaire supprime un projet `in_progress` avec 1 contribution `pending` et 1 `rejected` | 204 | 204 |
| E15 | Propriétaire supprime un projet `selling` sans contribution, avec 1 vente | 204 | 400 |
| E16 | Propriétaire supprime un projet `completed` sans contribution ni vente | 204 | 400 |
| E17 | Admin (non propriétaire) supprime un projet `published` vide | 403 | 403 (garde-fou) |
| E18 | Frontend : statut suivant de `published`, `in_progress`, `selling`, `completed`, `draft` | — | `in_progress`, `selling`, `completed`, aucun, aucun |

## Cas limites

- Projet `completed` et non-membre : la réponse d'accès actuelle (403/404) prime sur le 400 de statut.
- `PATCH` sur un projet `completed` qui ne porte que `status` : déjà refusé par la table des transitions (400).
- `PATCH` d'un projet `selling` qui porte `status: completed` et `salesTaxPercent` : la taxe s'applique avant la clôture, comme aujourd'hui (les champs sont lus dans l'ordre) ; accepté.

## Hors périmètre

- Statut `draft` : aucun chemin n'y mène et aucune transition n'en sort. Le supprimer ou lui donner un sens est une décision produit à part.
- Restreindre les ventes au statut `selling`, ou les contributions aux statuts `published`/`in_progress`.
- Départ ou exclusion d'un membre sur un projet terminé.
- Suppression de compte (#53) : un projet sans successeur reste supprimé avec le compte, même terminé (règle RGPD de #53).
- Masquer côté frontend les boutons de revue, de vente et de frais sur un projet terminé (le backend refuse).

## Glossaire

Terme ajouté à [`docs/glossary.md`](../glossary.md), section « Industrie collaborative » :

- **Projet terminé** (Completed project) : projet collaboratif au statut `completed`, atteint depuis `selling`. Ses comptes sont figés : plus de contribution, de revue, de vente ni de changement de frais, et il ne peut plus être supprimé. Un projet qui a une contribution approuvée ou une vente ne peut pas non plus être supprimé. Code : `GroupProjectStatus::Completed`, `UpdateGroupProjectProcessor`, `GroupProjectDeleteProvider`.

## Décisions prises en autonomie (mode nuit)

- 400 (et non 409) pour tous les refus de statut, comme les refus voisins du module.
- Les ventes bloquent la suppression au même titre que les contributions approuvées (elles servent à la distribution).
- Nom et container restent modifiables sur un projet terminé.
- Frontend minimal : bouton de statut suivant, suppression et « Contribute » masqués sur un projet terminé.
