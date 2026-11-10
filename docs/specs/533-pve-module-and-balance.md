# Spec #533 — Module PVE (Revenus PVE, Escalations), escalation vendue comptée, Revenus devient Bilan

Lot 3 `group:revenue-pve`, `circuit:l`, mode nuit (spec validée en autonomie, décisions notées). Cible : `.claude/tasks/ux-architecture-2026-11-09.md` §4-5 (B1, B2, D4), maquette `navigation.html`. Barre d'onglets : `ModuleTabBar` et `useModuleLastPage` de #531, pas de second composant. Rien n'est retiré : tout est déplacé (config des types PVE, période, compta corpo, compta industrie).

## Preuves de l'issue vérifiées
- Vrai : aucune lecture d'`Escalation` dans `src/Service/Pve`, `StatsProvider`, `StatsDailyProvider`, `LedgerDashboardProvider`, `LedgerDailyStatsProvider` (tout passe par `PveIncomeRepository::getTotalsByType` / `getDailyTotalsByType`, filtré sur `PveIncomeType`).
- Vrai : `Escalation` n'a pas de date de vente (seulement `updatedAt`, modifié à chaque édition) : une colonne est nécessaire.
- Vrai : `Ledger.vue` porte 5 onglets (`dashboard|pve|industry|escalations|settings`), le sélecteur de période ne couvre pas `EscalationsTab`.
- Aucun lien backend (notification, mail) vers `/ledger`, `/pve` ou `/escalations` ; seul le texte partagé Discord de `useEscalationHelpers.ts` pointe `/escalations` (couvert par la redirection).

## Règles

**R1 — Routes.** Noms nouveaux, chemins du frontend (sans `/api`).

| Page | Chemin | Nom | Contenu |
|---|---|---|---|
| Revenus PVE | `/pve/revenue` | `pve-revenue` | `PveTab` + sélecteur de période (`useLedgerPeriod`) |
| Escalations | `/pve/escalations` | `pve-escalations` | `EscalationsTab`, sans sélecteur de période |
| Bilan | `/balance` | `balance` | onglets `?tab=` : `dashboard` (défaut), `industry`, `settings` ; sélecteur de période |

`Ledger.vue` devient `Balance.vue` (déplacement, mêmes composants `LedgerDashboardTab`, `LedgerIndustryTab`, `LedgerSettingsTab`, mêmes appels de store). `/pve` redirige vers `/pve/revenue`.

**R2 — Anciennes URL (redirections ; autres paramètres de query conservés ; la redirection existante `/ledger?tab=mining…` de #531 reste prioritaire).**

| Ancienne | Cible |
|---|---|
| `/ledger`, `/ledger?tab=dashboard` | `/balance` |
| `/ledger?tab=pve` | `/pve/revenue` |
| `/ledger?tab=escalations` | `/pve/escalations` |
| `/ledger?tab=industry` | `/balance?tab=industry` |
| `/ledger?tab=settings` | `/balance?tab=settings` |
| `/escalations` | `/pve/escalations` |
| `/pve` | `/pve/revenue` |

Un `tab` inconnu sur `/ledger` donne `/balance`.

**R3 — Barre d'onglets PVE.** Composant `PveModuleTabs` (sur le modèle de `MiningModuleTabs`) avec 2 onglets, monté en tête de chacune des deux pages. `useModuleLastPage` mémorise `pve: ['/pve/revenue', '/pve/escalations']` (clé commune `evetools_module_last_page`).

**R4 — Sidebar et palette (modifications minimales, #536 réorganise).** Nouvelle entrée de premier niveau `pve` (route `/pve/revenue`, préfixe actif `/pve/`, libellé `nav.pve`) ; l'entrée `ledger` garde son id (réglage « modules masqués » préservé), pointe `/balance`, libellé « Bilan » / « Balance » (`nav.balance`). Palette (`useNavigationEntries`) : entrée Bilan + onglets `industry`, `settings` ; entrées « PVE › Escalations » (page, `isTab: true`, comme Mining) ; les anciens onglets de `ledger` (`pve`, `escalations`) sont retirés de la table car ils sont devenus des pages. Les clés i18n `ledger.tabs.pve|escalations` et `nav.revenue` sont conservées (pas de retrait).

**R5 — Une escalation vendue est un revenu PVE.** Nouveau type `PveIncomeType::Escalation` (`escalation`). Un `PveIncome` de ce type existe pour chaque escalation au statut `vendu` :
- montant = prix de l'escalation ; date = **date de vente** ; description = `type` + système ; lien `escalation_id` (unique) ;
- passage `envente → vendu` : crée le revenu, `soldAt` = maintenant ; passage `vendu → envente` : supprime le revenu, `soldAt` = null ; modification du prix d'une escalation vendue : le revenu suit ; `vendu → vendu` ne change pas `soldAt` ;
- suppression de l'escalation : le revenu est **conservé** (clé étrangère `ON DELETE SET NULL`) : le revenu perçu ne disparaît pas avec la ligne de suivi ;
- seul le propriétaire (`isOwnedBy`, contrôle déjà présent dans `UpdateEscalationProcessor`) génère le revenu, sur son compte ; visibilité (perso, corp, alliance, public) sans effet.

**R6 — Comptage.** Les revenus `escalation` entrent dans : le total PVE du Bilan (`totals.pve`, `pveBreakdown.escalations`), `pvePercent`, le total de revenu, `StatsProvider` (`totals.escalations`, inclus dans `totals.income`), les séries journalières (`LedgerDailyStatsProvider`, `StatsDailyProvider`, au jour de vente), la liste `/pve/income` (`refTypeLabel` « Escalation »). Le réglage de compta corpo n'a aucun effet sur eux. Les fenêtres `days` existantes (de `now − days` à `now`) s'appliquent à la date de vente.

**R7 — Pas de double compte automatique (décision).** Aucun rapprochement entre un revenu `escalation` et une vente de loot (`loot_sale`) saisie pour la même chose : deux lignes distinctes, et l'écran de vente d'une escalation affiche un avertissement court « ne saisissez pas aussi une vente de loot pour cette escalation ». Raison : aucune clé fiable pour les rapprocher, le rapprochement silencieux supprimerait des revenus réels.

**R8 — Migration de données.** Une migration ajoute `escalations.sold_at` (nullable) et `pve_income.escalation_id` (uuid nullable, unique, FK `ON DELETE SET NULL`). Pour les escalations déjà `vendu` : `sold_at = updated_at` et un revenu est créé à cette date (approximation : dernière modification ; écrite dans « Actions Sylvain »). `down()` supprime les revenus `escalation` puis les colonnes.

**R9 — Bilan : synthèse par activité.** `LedgerDashboardTab` garde ses blocs et ajoute, sur chaque carte d'activité, un lien vers son module : PVE → `/pve/revenue`, Minage → `/mining/my-mining`, Industrie → `/industry`. Le bloc PVE montre la ligne « Escalations » du détail (R6).

## Exemples chiffrés (tests rouges)

| Cas | Entrée | Sortie attendue |
|---|---|---|
| Critère de l'issue | Escalation 100 000 000 ISK passée à `vendu` le jour J ; période `days=30` contenant J | `/pve/stats` : `totals.escalations = 100000000`, `totals.income` +100 M ; `/ledger/dashboard` : `totals.pve` +100 M, `pveBreakdown.escalations = 100000000` ; série journalière du jour J +100 M |
| Hors période | même vente, J = aujourd'hui − 40 j, `days=30` | 0 dans les deux |
| Retour en vente | `vendu → envente` | revenu supprimé, `soldAt` null, totaux revenus à l'état d'avant |
| Prix modifié | vendue 100 M puis `price = 80 000 000` | revenu = 80 M, date inchangée |
| Re-sauvegarde | `vendu → vendu` (notes modifiées) | `soldAt` inchangé, un seul revenu |
| Suppression | escalation vendue supprimée | revenu conservé, `escalation_id` null |
| Autre utilisateur | escalation corp d'un autre membre vendue | aucun revenu pour moi |
| Compta corpo | réglage `mining` | revenu `escalation` toujours compté |
| Redirections | chaque ligne de R2 | cible exacte, query conservée |
| Barre d'onglets | `/pve/escalations` | onglet « Escalations » `aria-current`, 2 liens ; clic sur « PVE » de la sidebar rouvre la dernière page |

## Cas limites
- `price` à 0 sur une escalation vendue : revenu de 0 créé (valeur connue), pas de suppression.
- Escalation créée directement au statut `vendu` (si l'API l'accepte) : même chemin que R5.
- Dates : `soldAt` en UTC ; la série journalière groupe par date comme les autres revenus.
- Notifications Mercure existantes de `UpdateEscalationProcessor` inchangées ; la synchro de revenu ne publie pas de topic (pas d'action asynchrone).
- `PveIncome` d'une escalation ne doit pas être supprimé par les imports (`ImportLoot*`) ni par le scan de contrats : ils ne lisent que leurs propres types/ids.

## Accès et fraîcheur
Aucune nouvelle ressource API. Les revenus restent scopés par `user` comme les autres `PveIncome`. Le seul écrivain est `UpdateEscalationProcessor` (et `CreateEscalationProcessor` si statut initial `vendu`), qui vérifie déjà la propriété. Pas de donnée ESI : aucun risque 420.

## Tests existants modifiés
- `frontend/tests/views/LedgerPeriodAll.spec.ts` (monte `Ledger.vue`) : vise `Balance.vue` ; `NavigationEntryTabsContract.spec.ts` et `useNavigationEntries.spec.ts` (onglets `ledger`) : nouvelles entrées R4, listes exhaustives mises à jour avec leurs valeurs exactes.
- Tests de `UpdateEscalationProcessor`, `StatsProvider`, `LedgerDashboardProvider`, `IncomeProvider` : étendus (clés `escalations` ajoutées aux tableaux attendus).

## Hors périmètre
Sidebar finale à 8 modules et groupe Patrimoine (#536) ; page Paramètres (#536) ; Accueil Pont (#535/#539) ; rapprochement escalation ↔ vente de loot (R7) ; retrait de `LedgerSettingsTab` ou des types PVE ; suppression de `EscalationsTab`/`PveTab`.

## Glossaire
Ajouts : **Revenu d'escalation**, **Bilan**, **Module PVE** (section Interface).
