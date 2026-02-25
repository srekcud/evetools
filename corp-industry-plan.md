# Corporation Industry - Plan complet

## Contexte

Le module Industry personnel est mature (8 entités, 18 services, 30+ composants). Le **Corporation Industry** étend ce système pour permettre à une corporation EVE Online de planifier collectivement la production, suivre les contributions de chaque membre, et distribuer les profits proportionnellement. Le coeur du calcul (`IndustryTreeService::buildProductionTree()`) est déjà réutilisable tel quel.

---

## A. Vision fonctionnelle

### Flux utilisateur

1. **Manager** crée un Corp Project (ex: "100x Ferox") → BOM auto-générée via `IndustryTreeService`
2. Manager **publie** le projet → visible par tous les membres corpo (via `User::getCorporationId()`)
3. **Contributeurs** ajoutent des contributions (matériaux, BPC, manufacturing, réactions, transport)
4. Manager **approuve/rejette** chaque contribution → BOM mise à jour automatiquement
5. Production terminée → Manager enregistre les **ventes** (partielles ou totales)
6. Manager **clôture** le projet → distribution des profits calculée et affichée

### Modèle de rémunération : Remboursement + Profit proportionnel

**Principe** : chaque contributeur est **remboursé de ses frais réels** puis reçoit sa **part proportionnelle du bénéfice**.

#### Types de contributions et leur valorisation

| Type contribution | Valorisation (= coût engagé) |
|-------------------|------------------------------|
| **Matériaux fournis** | Prix Jita weighted au moment de l'apport (`JitaMarketService::getWeightedSellPrice()`) |
| **Coût job install ESI** | Coût réel du job ESI (EIV x cost_index x runs + facility tax) — via `EsiCostIndexService`. Calcul automatique, le contributeur choisit système + runs |
| **BPC fourni** | Coût d'invention (`InventionService::calculateInventionCost()`) ou prix contrat si acheté |
| **Line rental (manufacturing)** | Barème par catégorie de produit (voir table ci-dessous) x runs |
| **Line rental (réactions)** | Barème par catégorie x runs |
| **Transport** | Volume m3 x taux ISK/m3 |

#### Barème Line Rental (configurable par le Manager)

| Catégorie produit | ISK par run |
|-------------------|-------------|
| Location facteur base | 1,000,000 /jour |
| Compo T1 | 250 |
| Module T1 | 10,000 |
| Ship T1 | 100,000 |
| Module T2 | 100,000 |
| Ship T2 | 2,500,000 |
| Cap T1 | 25,000 |
| Cap T2 | 125,000 |

**Scope du barème** : barème global corpo + override par projet.
- Barème corpo par défaut : stocké dans `CorpIndustryCorpSettings` (1 par corpo, créé automatiquement)
- Override projet : champ `lineRentalRates` sur `CorpIndustryProject` (null = utilise barème corpo)
- Le Manager peut ajuster les taux par projet lors de la création ou via PATCH

#### Formule de distribution

```
coût_total_projet = Σ toutes_contributions_approuvées (matériaux + job_install + BPC + line_rental + transport)
revenu_net = total_revenue - (total_revenue × (broker_fee + sales_tax)) - deductible_expenses
profit = revenu_net - coût_total_projet
marge_% = profit / coût_total_projet

Pour chaque membre :
  frais_engagés = Σ ses contributions approuvées
  part_profit = frais_engagés × marge_%
  payout = frais_engagés + part_profit
         = frais_engagés × (1 + marge_%)
         = frais_engagés × (revenu_net / coût_total_projet)
```

**Exemple** : Projet coûtant 100 ISK, vendu 110 ISK (marge 10%)
- Alice a contribué 50 ISK (matériaux) → reçoit 50 + 5 = **55 ISK**
- Bob a contribué 30 ISK (manufacturing + line rental) → reçoit 30 + 3 = **33 ISK**
- Charlie a contribué 20 ISK (BPC + transport) → reçoit 20 + 2 = **22 ISK**

Si le projet est en perte (marge négative), chaque membre absorbe sa part proportionnelle de la perte.

---

## B. Plan des mockups (5 fichiers)

Mockups à créer dans `/home/sdu/Documents/projects/perso/evetools/mockups/` :

- file:///home/sdu/Documents/projects/perso/evetools/mockups/corp-industry-projects.html
- file:///home/sdu/Documents/projects/perso/evetools/mockups/corp-industry-detail-bom.html
- file:///home/sdu/Documents/projects/perso/evetools/mockups/corp-industry-detail-contributions.html
- file:///home/sdu/Documents/projects/perso/evetools/mockups/corp-industry-detail-distribution.html
- file:///home/sdu/Documents/projects/perso/evetools/mockups/corp-industry-contribute-modal.html

### 1. `mockups/corp-industry-projects.html` — Liste des projets

- Header : titre "Corporation Industry" + badge corporation + bouton "+ New Project"
- KPI row : Active Projects, Total Contributions ISK, Pending Approvals, Pipeline Value
- Filtre par statut (pills) : All / Published / In Progress / Selling / Completed
- Cards projet : icône produit 64px, nom, runs, status badge, ring progress (BOM fulfillment %), manager name, nb contributeurs, profit estimé
- Empty state
- Données fictives : 3 projets (Ferox 45%, Ishtar 78%, Sabre 100%)

### 2. `mockups/corp-industry-detail-bom.html` — Détail projet, onglet BOM

- Header projet : icône 128px, nom, runs, ME/TE, statut, manager, boutons action (Publish/Edit/Delete)
- Tab bar : BOM | Contributions | Sales | Distribution
- Table BOM groupée par stage (accordéons) : Raw Materials, Reactions, Components, Final Product
  - Colonnes : Icon, Item, Required Qty, Fulfilled Qty, Remaining, Progress Bar, Est. Cost
  - Couleurs : vert (fulfilled), amber (partiel), rouge (0)
- Footer résumé : Total items, Total est. cost, Overall fulfillment %

### 3. `mockups/corp-industry-detail-contributions.html` — Onglet Contributions

- Bouton "+ Add Contribution"
- Table contributions : Character (portrait), Type (badge: material/job_install/bpc/line_rental/transport), Item, Qty, Valuation ISK, Method, Status (badge pending/approved/rejected), Actions (Approve/Reject pour manager, Withdraw pour contributeur)
- Filtres : type de contribution, statut
- Summary bar : Total frais engagés, Approved value, Pending value
- Données fictives : 6-7 contributions variées :
  - Alice : 500k Tritanium (material, 45M ISK jita_weighted)
  - Alice : Job install Ferox x10 (job_install, 2.3M ISK esi_job_cost)
  - Bob : 50x Ferox BPC (bpc, 15M ISK invention_cost)
  - Bob : Line rental Ship T1 x50 (line_rental, 5M ISK @ 100k/run)
  - Charlie : Location factor 3 jours (line_rental, 3M ISK @ 1M/jour)
  - Charlie : Transport 50,000 m3 (transport, 60M ISK @ 1200/m3)

### 4. `mockups/corp-industry-detail-distribution.html` — Onglets Sales + Distribution

**Sales :**
- Bouton "Record Sale" (manager only)
- Table : Date, Quantity, Unit Price, Revenue, Venue
- Total revenue row

**Distribution :**
- Résumé financier : Revenu total, Taxes (broker + sales), Revenu net, Coût total projet, Profit, Marge %
- Ring chart SVG (segments colorés par membre, proportionnel aux contributions)
- Table distribution : Character, Frais Engagés, Share %, Profit Part, **Payout Total** (frais + profit)
- Détail expandable par membre : breakdown par type de contribution (material, job_install, bpc, line_rental, transport)
- Footer : formule visible `Payout = Frais × (1 + Marge%)` avec exemple chiffré
- Badge "LOSS" rouge si marge négative (chacun absorbe sa part de la perte)

### 5. `mockups/corp-industry-contribute-modal.html` — Modal d'ajout contribution

- Sélecteur type (radio + icônes) : Material, Job Install (ESI), BPC, Line Rental, Transport
- Formulaire dynamique selon type :
  - **Material** : recherche type + quantité + prix Jita auto-affiché
  - **Job Install** : sélection step BOM + runs + système + coût ESI auto-calculé (EIV x cost_index)
  - **BPC** : recherche type + quantité + valorisation auto (invention cost) ou manuelle (prix contrat)
  - **Line Rental** : sélection step BOM + runs + catégorie auto-détectée + barème affiché + durée jours optionnelle (location factor)
  - **Transport** : volume m3 + taux par m3
- Preview valorisation ISK avant soumission (calcul en temps réel)
- Notes textarea
- Submit / Cancel

---

## C. Architecture backend

### C.1 Enums (3)

```
CorpProjectStatus: draft, published, in_progress, selling, completed, cancelled
ContributionType: material, job_install, bpc, line_rental, transport
ContributionStatus: pending, approved, rejected
```

### C.2 Entités (5)

#### `CorpIndustryProject`
- `id: Uuid`, `user: ManyToOne User (CASCADE)` — le Manager
- `corporationId: int`, `corporationName: string`
- `productTypeId: int`, `name: ?string`, `runs: int`, `meLevel: int`, `teLevel: int`
- `status: CorpProjectStatus` (default: draft)
- `maxJobDurationDays: float` (default 2.0), `excludedTypeIds: json`
- `notes: ?text`
- `totalRevenue: ?float`, `deductibleExpenses: float` (default 0)
- `brokerFeeRate: float` (default 0.036), `salesTaxRate: float` (default 0.036)
- `lineRentalRates: ?json` — barème line rental override par projet (null = utilise barème corpo), default :
  ```json
  {
    "location_factor_per_day": 1000000,
    "compo_t1": 250,
    "module_t1": 10000,
    "ship_t1": 100000,
    "module_t2": 100000,
    "ship_t2": 2500000,
    "cap_t1": 25000,
    "cap_t2": 125000
  }
  ```
- `createdAt, publishedAt, completedAt: DateTimeImmutable`
- Relations : `OneToMany` → BomItem, Contribution, Sale (cascade persist+remove)
- Méthodes : `getTotalContributionValue()`, `getNetRevenue()`, `getProfit()`, `getProfitMargin()`, `getMemberPayout(User)`, `getEffectiveLineRentalRates(CorpIndustryCorpSettings)` (fallback corpo)
- Index : `[corporation_id, status]`

#### `CorpIndustryBomItem`
- `id: Uuid`, `project: ManyToOne CorpIndustryProject (CASCADE)`
- `typeId: int`, `typeName: string`
- `requiredQuantity: int`, `fulfilledQuantity: int` (default 0)
- `stage: string` (raw_material, reaction, component, final_product)
- `blueprintTypeId: ?int`, `activityType: ?string` (manufacturing, reaction, copy)
- `depth: int`, `estimatedUnitPrice: ?float`, `sortOrder: int`

#### `CorpIndustryContribution`
- `id: Uuid`, `project: ManyToOne CorpIndustryProject (CASCADE)`, `user: ManyToOne User (CASCADE)`
- `characterId: int`, `characterName: string`
- `contributionType: ContributionType` (material, job_install, bpc, line_rental, transport)
- `typeId: ?int`, `typeName: ?string`, `quantity: int`
- `valuation: float` — coût ISK engagé (auto-calculé ou saisi)
- `valuationMethod: string` (jita_weighted, esi_job_cost, invention_cost, contract_price, line_rental_rate, transport_rate)
- `bomItemId: ?Uuid` — référence optionnelle vers le BomItem concerné (pour line_rental et job_install)
- `solarSystemId: ?int` — système de production (pour job_install)
- `durationDays: ?float` — durée en jours (pour location factor dans line_rental)
- `notes: ?string`, `status: ContributionStatus` (default: pending)
- `reviewedBy: ?ManyToOne User`, `reviewedAt: ?DateTimeImmutable`, `createdAt: DateTimeImmutable`
- Index : `[project_id, user_id]`, `[project_id, status]`

#### `CorpIndustryCorpSettings`
- `id: Uuid`, `corporationId: int` (UNIQUE), `corporationName: string`
- `lineRentalRates: json` — barème global de la corpo, mêmes clés que ci-dessus
- `createdBy: ManyToOne User (CASCADE)` — qui a créé/modifié
- `updatedAt: DateTimeImmutable`
- Créé automatiquement lors du premier projet de groupe de la corpo (avec valeurs par défaut)
- Un seul enregistrement par corpo (UNIQUE sur corporationId)

#### `CorpIndustrySale`
- `id: Uuid`, `project: ManyToOne CorpIndustryProject (CASCADE)`, `recordedBy: ManyToOne User (CASCADE)`
- `quantity: int`, `unitPrice: float`, `totalRevenue: float`
- `venue: string` (jita, structure, contract, other), `venueName: ?string`
- `soldAt: DateTimeImmutable`, `createdAt: DateTimeImmutable`

### C.3 Services (2 nouveaux)

#### `CorpIndustryService` (`src/Service/CorpIndustry/CorpIndustryService.php`)
- `createProject(User, CreateCorpProjectInput): CorpIndustryProject` — crée projet + BOM via `IndustryTreeService::buildProductionTree()`, flatten en BomItems avec prix Jita snapshot. Crée `CorpIndustryCorpSettings` si premier projet corpo.
- `publishProject(project): void` — valide BOM, status → published, Mercure
- `closeProject(project): void` — status → completed, calcul distribution finale
- `computeDistribution(project): array` — calcule parts par membre (remboursement + profit proportionnel)
- `regenerateBom(project): void` — re-génère BOM

Dépendances : `IndustryTreeService`, `JitaMarketService`, `MercurePublisherService`, `EntityManagerInterface`, `CorpIndustryCorpSettingsRepository`

#### `CorpContributionValuationService` (`src/Service/CorpIndustry/CorpContributionValuationService.php`)
- `valuateMaterial(typeId, quantity): float` — `JitaMarketService::getWeightedSellPrice()` x quantity
- `valuateJobInstallCost(typeId, runs, systemId, facilityTaxRate): float` — calcul auto via `EsiCostIndexService::calculateJobInstallCost()` (EIV x cost_index x runs + facility tax). Le contributeur choisit le système et les runs, le coût est calculé automatiquement — pas de saisie manuelle
- `valuateBpc(typeId, ?quantity): float` — `InventionService::calculateInventionCost()` ou prix contrat
- `valuateLineRental(productTypeId, runs, lineRentalRates): float` — lookup catégorie produit (SDE group → catégorie) puis `rate_per_run x runs`. Pour le location factor : `location_factor_per_day x duration_days`
- `valuateTransport(volumeM3, ratePerM3): float` — volume x taux
- `resolveProductCategory(typeId): string` — détermine la catégorie (compo_t1, module_t1, ship_t1, etc.) via SDE group/category + meta group (T1/T2)

Dépendances : `JitaMarketService`, `InventionService`, `EsiCostIndexService`, `SdeTypeRepository`

### C.4 Modifications existantes

- **`MercurePublisherService`** : ajouter `publishCorpIndustryEvent(action, data, corporationId)` + topic `/corp/{corpId}/corp-industry` dans `getGroupTopics()`

### C.5 API Resources & Endpoints

#### `CorpProjectResource`
| Méthode | URI | Input | Provider/Processor |
|---------|-----|-------|--------------------|
| GET | `/corp-industry/projects` | — | `CorpProjectCollectionProvider` |
| GET | `/corp-industry/projects/{id}` | — | `CorpProjectProvider` |
| POST | `/corp-industry/projects` | `CreateCorpProjectInput` | `CreateCorpProjectProcessor` |
| PATCH | `/corp-industry/projects/{id}` | `UpdateCorpProjectInput` | `UpdateCorpProjectProcessor` |
| DELETE | `/corp-industry/projects/{id}` | — | `DeleteCorpProjectProcessor` |
| POST | `/corp-industry/projects/{id}/publish` | `EmptyInput` | `PublishCorpProjectProcessor` |
| POST | `/corp-industry/projects/{id}/close` | `EmptyInput` | `CloseCorpProjectProcessor` |

#### `CorpContributionResource`
| Méthode | URI | Input | Provider/Processor |
|---------|-----|-------|--------------------|
| GET | `/corp-industry/projects/{id}/contributions` | — | `CorpContributionCollectionProvider` |
| POST | `/corp-industry/projects/{id}/contributions` | `CreateContributionInput` | `CreateContributionProcessor` |
| PATCH | `/corp-industry/contributions/{id}/review` | `ReviewContributionInput` | `ReviewContributionProcessor` |
| DELETE | `/corp-industry/contributions/{id}` | — | `DeleteContributionProcessor` |

#### `CorpSaleResource`
| Méthode | URI | Input | Provider/Processor |
|---------|-----|-------|--------------------|
| GET | `/corp-industry/projects/{id}/sales` | — | `CorpSaleCollectionProvider` |
| POST | `/corp-industry/projects/{id}/sales` | `CreateSaleInput` | `CreateSaleProcessor` |
| DELETE | `/corp-industry/sales/{id}` | — | `DeleteSaleProcessor` |

#### `CorpCorpSettingsResource`
| Méthode | URI | Input | Provider/Processor |
|---------|-----|-------|--------------------|
| GET | `/corp-industry/corp-settings` | — | `CorpCorpSettingsProvider` |
| PATCH | `/corp-industry/corp-settings` | `UpdateCorpSettingsInput` | `UpdateCorpSettingsProcessor` |

#### `CorpDistributionResource` — pas de CRUD, calculé dans le `CorpProjectProvider` (détail)

### C.6 Providers (8) & Processors (11)

**Providers** : CorpProjectCollectionProvider, CorpProjectProvider, CorpProjectDeleteProvider, CorpContributionCollectionProvider, CorpContributionDeleteProvider, CorpSaleCollectionProvider, CorpSaleDeleteProvider, CorpCorpSettingsProvider

**Processors** : CreateCorpProjectProcessor, UpdateCorpProjectProcessor, DeleteCorpProjectProcessor, PublishCorpProjectProcessor, CloseCorpProjectProcessor, CreateContributionProcessor, ReviewContributionProcessor, DeleteContributionProcessor, CreateSaleProcessor, DeleteSaleProcessor, UpdateCorpSettingsProcessor

**Pattern commun** — vérification corp membership :
```php
isSameCorporation(User, CorpIndustryProject): bool  // user->getCorporationId() === project->getCorporationId()
isProjectManager(User, CorpIndustryProject): bool    // user->getId() === project->getUser()->getId()
```

### C.7 Mercure

- Topic : `/corp/{corporationId}/corp-industry`
- Events : `project_published`, `project_updated`, `project_completed`, `project_deleted`, `contribution_added`, `contribution_reviewed`, `sale_recorded`

### C.8 Tests unitaires

- `CorpIndustryServiceTest` : createProject (BOM generation), publishProject, closeProject, computeDistribution (profit et perte)
- `CorpContributionValuationServiceTest` : chaque méthode de valorisation, resolveProductCategory

---

## D. Architecture frontend

### D.1 Navigation

**Nouveau menu principal** dans `MainLayout.vue` sous le groupe Production :
```
Production
  - Industry (personnel)    → /industry
  - Corporation Industry    → /corp-industry   ← NOUVEAU
  - Appraisal               → /appraisal
  - Planetary               → /planetary
```

Justification : module séparé car multi-utilisateur, corp-wide, avec workflow d'approbation — fondamentalement différent de l'Industry personnel.

### D.2 Route

```typescript
{ path: '/corp-industry', name: 'corp-industry', component: () => import('@/views/CorpIndustry.vue'), meta: { requiresAuth: true } }
```

### D.3 Vue principale : `CorpIndustry.vue`

- Wraps `<MainLayout>`
- Deux modes : liste (CorpProjectList) et détail (CorpProjectDetail)
- Mercure listener sur `/corp/{corpId}/corp-industry`

### D.4 Composants (9)

| Composant | Responsabilité |
|-----------|---------------|
| `CorpProjectList.vue` | Liste projets corpo, filtres statut, KPIs |
| `CorpProjectDetail.vue` | Détail avec tabs (BOM/Contributions/Sales/Distribution) |
| `CorpProjectCreateModal.vue` | Modal création (réutilise `ProductSearch.vue` existant) |
| `CorpBomTable.vue` | Table BOM groupée par stage avec progress bars |
| `CorpContributionPanel.vue` | Liste contributions + approve/reject (manager) |
| `CorpContributeModal.vue` | Modal ajout contribution avec formulaire dynamique |
| `CorpSalesPanel.vue` | Liste ventes + formulaire ajout (manager) |
| `CorpDistributionChart.vue` | Ring chart SVG + table distribution par membre |
| `CorpProjectSummaryCard.vue` | Card résumé pour la liste |

### D.5 Store : `stores/corpIndustry.ts`

State : `projects`, `currentProject`, `corpSettings`, `isLoading`, `error`
Actions : `fetchProjects`, `fetchProject`, `createProject`, `updateProject`, `deleteProject`, `publishProject`, `closeProject`, `addContribution`, `reviewContribution`, `deleteContribution`, `recordSale`, `deleteSale`, `fetchCorpSettings`, `updateCorpSettings`
Getters : `activeProjects`, `isManager(project)`

Types dans `stores/corp-industry/types.ts` : CorpProject, CorpBomItem, CorpContribution, CorpSale, CorpDistribution, CorpSettings

### D.6 i18n

Clés EN + FR pour : titres, statuts, types contribution, actions, messages vides, formules

---

## E. Ordre d'implémentation

### Phase 0 : Mockups (Architecte)
Créer les 5 mockups HTML → validation utilisateur → **aucun code avant approbation**

### Phase 1 : Backend Foundation (Backend Developer)
1. Enums (3 fichiers)
2. Entités (5 fichiers : Project, BomItem, Contribution, Sale, CorpSettings) + Repositories (4 fichiers)
3. Migration `doctrine:migrations:diff` + migrate
4. `CorpContributionValuationService` + tests
5. `CorpIndustryService` + tests
6. Modifier `MercurePublisherService` (topic + méthode)

### Phase 2 : Backend API (Backend Developer, après Phase 1)
1. API Resources (5) + Input DTOs (6)
2. `CorpIndustryResourceMapper`
3. Providers (8)
4. Processors (11)
5. Tests API

### Phase 3 : Frontend (Frontend Developer, après Phase 2)
1. Route + nav dans MainLayout
2. Types + Store
3. Vue CorpIndustry.vue
4. Composants (9) dans l'ordre : List → Detail → BOM → Contributions → Sales → Distribution → Modals
5. Mercure listener
6. i18n EN + FR
7. Tests manuels

**Parallélisation** : Phase 1 et les types/store frontend (squelette) peuvent démarrer en parallèle si le contrat API est figé.

---

## F. Fichiers critiques existants à consulter

| Fichier | Raison |
|---------|--------|
| `src/Service/Industry/IndustryTreeService.php` | `buildProductionTree()` — coeur du BOM, réutilisé directement |
| `src/Service/Mercure/MercurePublisherService.php` | Pattern corp-wide events, `getGroupTopics()` |
| `src/State/Provider/Escalation/EscalationCorpProvider.php` | Pattern provider corp-scoped (filtre par corporationId) |
| `src/ApiResource/Industry/ProjectResource.php` | Pattern API Resource le plus complet du projet |
| `frontend/src/stores/industry/projects.ts` | Pattern store Pinia à suivre |
| `frontend/src/views/Industry.vue` | Pattern vue avec tabs et Mercure |

---

## G. Vérification

1. **Backend** : tests unitaires (`docker compose exec app php vendor/bin/phpunit --no-coverage`)
2. **API** : tester chaque endpoint via curl/Postman (créer projet, publier, contribuer, approuver, enregistrer vente, clôturer)
3. **Mercure** : vérifier que les events arrivent sur le topic corp avec 2 utilisateurs de la même corpo
4. **Frontend** : test manuel dans le navigateur (création projet, contribution, distribution)
5. **Edge cases** : utilisateur change de corpo, manager quitte, projet vide, contribution rejetée
