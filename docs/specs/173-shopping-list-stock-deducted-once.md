# Spec #173 — Le stock collé dans la liste d'achats est déduit une seule fois

## Contexte

Depuis #121, le backend déduit le stock d'un produit fabriqué : stock = Σ `inStockQuantity` de ses étapes (R6), à fabriquer = besoin − stock, et ses matériaux baissent en conséquence (R4). Un intermédiaire blacklisté ou acheté est acheté à hauteur de besoin − stock (R5).

Sur `main` (313ec7a), le frontend refait cette déduction quand on colle un stock (`useShoppingStockAnalysis.ts`) :

- `cascadeStockToChildren()` (l. 190-219) augmente `inStockQuantity` des étapes enfants et ajoute au stock collé des matières premières que l'utilisateur ne possède pas ;
- `ShoppingTab.vue` (l. 139-150) soustrait ensuite le stock collé d'une quantité que le backend a déjà réduite ;
- `parsedStock` est mis à jour (l. 258) avant la lecture de l'ancienne quantité (l. 301), qui vaut donc toujours 0 : recoller le même stock l'ajoute de nouveau à l'étape.

## Règles

- **R1 — Coller un produit fabriqué remplace son stock d'étape.** Un type collé produit par une étape de production non racine et non achetée : Σ `inStockQuantity` de ses étapes = min(quantité collée, Σ `quantity`), rempli dans l'ordre de `splitIndex`, chaque étape plafonnée à sa `quantity`. La valeur précédente est remplacée, à la hausse comme à la baisse. Seules les étapes dont la valeur change reçoivent un `PATCH`.
- **R2 — Aucune cascade.** Coller un stock ne modifie aucune autre étape et n'ajoute rien au stock collé : la déduction sur les matériaux est faite par le backend.
- **R3 — Une seule déduction par ligne.** Pour une ligne de la liste d'achats, stock pris en compte = max(0, stock collé du type − Σ `inStockQuantity` des étapes de ce type) + `purchasedQuantity`. Pour une matière première (aucune étape), c'est le stock collé, comme aujourd'hui.
- **R4 — Inchangé.** Racine (jamais de stock d'étape), étape achetée (le stock collé reste déduit par le frontend seul), analyse des doublons stock/achats.

## Exemples

Étapes : Intermédiaire X (profondeur 1, `quantity` 100, `inStockQuantity` 0), enfant Y (profondeur 2, `quantity` 200), matière première Z dans la liste.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Coller « X 40 » | X → 40, Y → 80, Z ajouté au stock collé | `PATCH` X → 40 seul, stock collé = [X 40] |
| E2 | Coller « X 40 » deux fois | X → 80 au second collage | aucun `PATCH` au second collage, X reste 40 |
| E3 | Coller « X 40 » puis « X 30 » | X → 70 | X → 30 |
| E4 | X découpé en deux étapes (60 et 40), coller « X 70 » | 1re étape → 60, 2e inchangée | 60 et 10 |
| E5 | Coller « X 150 » | X → 100 | X → 100 |
| E6 | X blacklisté : ligne X `quantity` 60 (besoin 100, 40 déduits par le backend), étape X à 40, stock collé X 40 | manquant 20 | manquant 60 (= backend) |
| E7 | Matière première Z : ligne 100, stock collé 40 | manquant 60 | manquant 60 (garde-fou) |
| E8 | Étape X achetée : ligne X 100, coller « X 40 » | aucun `PATCH`, manquant 60 | inchangé |
| E9 | Coller le nom de la racine | aucun `PATCH` | inchangé |

## Hors périmètre

- Stock déjà gonflé en base (étapes enfants) ou en localStorage (matières premières ajoutées par l'ancienne cascade) : non corrigé automatiquement, « Vider le stock » puis recoller le remet d'aplomb.
- `useStockAnalysis()` (fonction non appelée, même cascade) ; `relevantStock` / `intermediatesInStock` (calculés, non affichés).
- #177 (écriture du coût matériaux).

## Décisions prises en autonomie (mode nuit, 2026-10-22)

- D1 : remplacer plutôt qu'additionner, y compris à la baisse (le collage est l'inventaire courant).
- D2 : répartition sur les étapes découpées par `splitIndex` (le backend somme toutes les étapes du type).
- D3 : R3 retranche le stock d'étape plutôt que d'exclure les intermédiaires : couvre aussi une saisie manuelle du stock d'étape sans collage.
