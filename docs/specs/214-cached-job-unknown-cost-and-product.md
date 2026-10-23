# Spec #214 — Job en cache : coût et produit inconnus restent inconnus, et suivent l'ESI

## Contexte

À la création d'un job en cache (`IndustryJobSyncService`), un `cost` absent de l'ESI devient `0` (`(float) ($jobData['cost'] ?? 0)`) et un `product_type_id` absent devient le blueprint (`product_type_id ?? blueprint_type_id`). Dans le schéma ESI des jobs (perso et corpo), `cost` et `product_type_id` sont optionnels (absents par exemple pour la recherche ME/TE) ; `runs` et `end_date` sont obligatoires. À la mise à jour d'un job existant, ni le coût, ni les runs, ni le produit ne sont rafraîchis (la date de fin l'est depuis la spec #208). Les notifications de fin de job (spec #216) réutilisent le repli produit → blueprint dans leurs données.

## Règles métier

- **R1 — Coût inconnu.** Un job ESI sans `cost` a un coût en cache `null`, jamais `0`. Les colonnes `cached_industry_jobs.cost` et `product_type_id` deviennent nullables.
- **R2 — Produit inconnu.** Un job ESI sans `product_type_id` a un produit en cache `null`, jamais le blueprint.
- **R3 — Le cache suit la dernière lecture ESI.** À chaque mise à jour d'un job existant, le coût, les runs et le produit reprennent les valeurs de l'ESI (`null` si le champ est absent), comme la date de fin (spec #208). Les jobs déjà en base avec un coût `0` ou un produit égal au blueprint sont ainsi corrigés à leur prochaine lecture ; la migration ne modifie aucune donnée existante.
- **R4 — Objet affiché d'un job.** Pour l'affichage (Slot Tracker, jobs disponibles, libellé des notifications), un job sans produit est désigné par son blueprint : c'est l'objet réel d'un job de recherche ou de copie. Cet objet affiché n'est jamais enregistré comme produit. Dans les données des notifications, `productTypeId` et `productName` valent le produit et son nom, ou `null`.
- **R5 — Coût affiché.** La liste des jobs disponibles expose `cost: null` pour un coût inconnu ; le front l'affiche `---` (comportement existant de `formatIsk`). Le job matché recopie `null` (spec #208, colonne déjà nullable).

## Exemples chiffrés

Personnage A. Job 500 : fabrication, blueprint 1001, produit 2001, 10 runs, coût 1 250 000,75. Job 501 : recherche ME (activité 4), blueprint 1001, sans `product_type_id`, sans `cost`, 2 runs.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | 501 inconnu en base, lu par l'ESI | coût 0, produit 1001 | coût `null`, produit `null` |
| E2 | 500 inconnu en base, lu par l'ESI | coût 1 250 000,75, produit 2001 | inchangé |
| E3 | 500 en cache (coût 1 000 000, 8 runs, produit 2001) ; l'ESI le renvoie coût 1 250 000,75, 10 runs | inchangé | coût 1 250 000,75, 10 runs |
| E4 | 501 en cache avec l'ancien repli (coût 0, produit 1001) ; l'ESI le renvoie sans coût ni produit | inchangé | coût `null`, produit `null` |
| E5 | Slot Tracker : 501 actif | produit affiché 1001 | objet affiché 1001 (nom du blueprint), produit en cache `null` |
| E6 | Jobs disponibles : 500 et 501 sur un projet du blueprint 1001 | 501 : coût 0, produit 1001 | 501 : `cost: null`, `productTypeId` = 1001 (objet affiché) |
| E7 | Notification de fin de 501 (`ready`) | libellé et `productTypeId` 1001 | libellé du blueprint 1001, `productTypeId: null` |
| E8 | 500 matché sur une étape ; l'ESI le renvoie sans `cost` | coût du job matché inchangé | job en cache et job matché : coût `null` |

## Cas limites

- Le coût total des jobs d'un projet (`IndustryProjectStep::getJobsCost()`) ignore aujourd'hui les jobs matchés sans coût : hors périmètre (à traiter avec #7, montant affiché).

## Hors périmètre

- Coût total des jobs quand un coût est inconnu (ci-dessus).
- Compteurs de fin de sync (#210, fusionné), relecture des jobs corpo (#213).

## Décisions prises en autonomie (mode nuit)

- R3 : le cache reprend aussi `null` quand l'ESI omet un champ à la mise à jour, plutôt que de garder la valeur précédente : c'est ce qui corrige les replis déjà en base sans migration de données (on ne peut pas distinguer un vrai coût 0 d'un repli).
- R4 : objet affiché = blueprint pour un job sans produit, plutôt qu'un produit `null` jusqu'au front : pas de changement d'affichage pour les joueurs, et la donnée enregistrée reste inconnue.
- Migration de schéma seule (`DROP NOT NULL`), aucune donnée modifiée.

## Glossaire

- **Objet affiché d'un job** (Job displayed type) : produit du job, ou son blueprint quand l'ESI ne donne pas de produit (recherche, copie) ; sert seulement à l'affichage, jamais enregistré comme produit (spec #214). `CachedIndustryJob::getDisplayedTypeId()`.
