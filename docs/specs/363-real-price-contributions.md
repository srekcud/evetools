# Spec #363 : valoriser chaque apport au prix réel (remplace la spec #154)

Décisions de Sylvain : issue du 2026-11-03 (stock apporté : toujours le prix marché, pas de prix payé saisi) ; questions du 2026-11-06 (percentile 5 % lu au dépôt ; achats et jobs liés du propriétaire = apports approuvés d'office ; valeur nullable, payouts inconnus).

## Contexte

Un apport (`IndustryProjectContribution`) vaut aujourd'hui : pour un matériau, le prix BOM figé à la création du projet (`IndustryProjectBomLine::$estimatedPrice`, percentile 5 % Jita) fois la quantité, une valeur saisie refusée si la ligne a un prix BOM (spec #154) ; pour une installation de job, un BPC et une location de ligne, une valeur saisie. Les achats et jobs liés du propriétaire (#362, D13) ne comptent nulle part. La répartition (`ProjectDistributionService`) somme les apports approuvés : le coût de production, la marge (profit / coût de production) et les payouts viennent de cette seule somme, qui doit donc porter la valeur réelle.

## Règles

- **R1 — Stock apporté au prix Jita du dépôt.** La valeur d'un apport matériau déposé est le prix percentile 5 % Jita (`JitaMarketService::getCheapestPercentilePrices`) lu à la soumission, fois la quantité, figé sur l'apport : revoir ou approuver plus tard ne le change pas. La spec #154 (R1 à R3, R5, R6) est remplacée ; `IndustryProjectBomLine::$estimatedPrice` reste l'estimation de la BOM (`totalBomValue`) et ne valorise plus d'apport.
- **R2 — Aucune valeur saisie pour un matériau.** Un apport matériau qui porte `estimatedValue` (même 0) est refusé (400, rien n'est enregistré). Sans prix Jita (type absent, prix ≤ 0) : 400, pas de repli sur une valeur saisie ni sur le prix BOM, jamais 0.
- **R3 — Achat lié = apport au prix payé.** Pour chaque ligne de BOM, le propriétaire a au plus un apport matériau dérivé de ses achats liés dont le type est celui de la ligne (ceux que compte `getPurchasedQuantity`) : quantité = somme des quantités, valeur = somme des `totalPrice` (prix payé du wallet, ou saisi pour un achat manuel). Il est `approved` d'office (`reviewedBy` = propriétaire), `isAutoDetected` et `isVerified` vrais. Sans achat de ce type, l'apport dérivé n'existe pas. La `fulfilledQuantity` de la ligne bouge de la différence de quantité.
- **R4 — Job lié = apport au coût ESI.** Pour chaque ligne de job de la BOM, le propriétaire a au plus un apport « installation de job » dérivé de ses jobs liés : quantité = somme des runs, valeur = somme des `cost` des liens (copiés de l'ESI par la sync). Mêmes statut et drapeaux que R3. Il ne change pas la `fulfilledQuantity` (les runs ne sont pas des unités, spec #362 D13 sur ce point).
- **R5 — Recalcul.** L'apport dérivé d'une ligne est recalculé par un seul service après : création ou suppression d'un achat, lien et suppression de lien de job, `match-jobs`, sync des jobs (`copyJobState` d'un lien) et transfert de propriété d'un compte supprimé (les liens de l'ancien propriétaire sont vidés : ses apports dérivés partent avec eux, `fulfilledQuantity` corrigée). Un apport dérivé qui change, apparaît ou disparaît publie `contribution_synced` sur `/group-project/{id}/events` (la sync des jobs est asynchrone).
- **R6 — Prix inconnu : valeur inconnue.** `estimatedValue` devient nullable. Un apport dérivé d'un job dont un lien n'a pas de coût a une valeur `null` (jamais 0). Tant qu'un apport approuvé a une valeur inconnue, le coût de production et la marge du projet sont `null`, ainsi que la part, le profit et le payout de chaque participant ; le coût engagé d'un participant, et son détail par type, est `null` s'il porte un apport inconnu. Même règle pour la somme approuvée d'un projet dans les listes (mes projets, découverte) et la valeur approuvée d'un participant.
- **R7 — Une valeur pour le profit et la répartition.** Coût de production = somme des valeurs des apports approuvés, lue par `ProjectDistributionService` seul ; marge = (revenu net − coût) / coût, inchangée. Aucune autre source de coût ne sert au profit.
- **R8 — Apports déclarés inchangés.** Installation de job, BPC et location de ligne déposés à la main gardent leur valeur saisie (positive, sauf location à 0), leur revue et `isVerified` faux. Seul le dépôt manuel d'un matériau change (R1, R2). Le contrôle anti-doublon (membre, ligne, type) ignore les apports dérivés.
- **R9 — Front.** La modale de dépôt d'un matériau n'envoie plus de valeur, n'affiche plus le prix BOM figé à la création mais « Prix Jita du jour d'apport (percentile 5 %) » et son total ; sans prix Jita connu, elle dit « inconnu » et refuse l'envoi. Les tableaux d'apports, de répartition et le résumé financier affichent « inconnu » (violet pointillé, cause) pour une valeur `null`, jamais 0. La méthode d'un apport se lit : dérivé matériau « prix payé (wallet) » ; dérivé job « coût réel (ESI) » ; déposé matériau « prix Jita du jour d'apport » ; autre « valeur saisie ». `contribution_synced` recharge BOM, apports, répartition et détail (`groupProjectRefreshes.ts`).
- **R10 — Migration.** `estimatedValue` nullable, lignes gardées, `down()` lève (D7 de #362). Aucune revalorisation des apports existants : #362 purge les projets au déploiement.

## Exemples (vérifiables par test)

P : propriétaire U, participant `accepted` V. Ligne L (Tritanium, typeId 34, 1 000 requis, prix BOM 5,5). Ligne FA (job de fabrication).

| # | Action | Résultat attendu |
|---|---|---|
| E1 | V dépose 1 000 Tritanium, percentile Jita 7,0 | `pending`, valeur 7 000 (pas 5 500), un seul appel au prix Jita |
| E2 | Même dépôt avec `estimatedValue` 7 000, puis 0 | 400 chacun, rien persisté |
| E3 | Même dépôt, pas de prix Jita pour le type 34 | 400 « No market price for type 34… », rien persisté |
| E4 | Le prix Jita passe à 8,0 puis un admin approuve E1 | valeur restée 7 000 |
| E5 | U seul dans P dépose 100 Tritanium à 7,0 | `approved`, valeur 700, L servie de 100 (existant) |
| E6 | U lie un achat de 400 à 6,5 puis un de 600 à 6,0 sur L | un seul apport U/L : quantité 1 000, valeur 6 200, `approved`, `isAutoDetected`, `isVerified` ; L servie de 1 000 |
| E7 | U supprime l'achat de 600, puis l'autre | quantité 400, valeur 2 600, L servie de 400 ; puis apport supprimé, L servie de 0 |
| E8 | U lie à L un achat de Pyerite | aucun apport dérivé |
| E9 | U lie à FA des jobs de 3 runs (cost 214 000) et 2 runs (150 000) | un apport « installation de job » : quantité 5, valeur 364 000, `approved` ; `fulfilledQuantity` de FA inchangée |
| E10 | Le lien de 2 runs n'a pas de coût | valeur `null`, apport existant |
| E11 | La sync copie un coût de 220 000 sur le premier lien | valeur 370 000, `contribution_synced` publié avec `projectId` |
| E12 | Apports approuvés U 3 000, V 1 000 ; ventes 5 000 ; courtage 2 %, taxe 3 % | coût 4 000, revenu net 4 750, marge 18,75 % ; payouts U 3 562,5 et V 1 187,5 |
| E13 | E12 plus un apport approuvé de valeur `null` | coût, marge, parts, profits et payouts `null` ; ni 0 ni valeur partielle |
| E14 | Même cas dans mes projets et la découverte | valeur approuvée `null` |
| E15 | Un apport manuel de U sur L alors que L a son apport dérivé | accepté (le doublon ignore les dérivés) |
| E16 | Un admin V lie un achat ou un job sur P | 403 (inchangé) |
| E17 | V dépose une installation de job de 0, puis de 5 000 | 400 « must be positive » ; `pending`, valeur 5 000 |
| E18 | Suppression du compte de U avec V successeur, après E6 et E9 | apports dérivés de U supprimés, `fulfilledQuantity` de L corrigée |
| E19 | Migration sur une base avec un apport valorisé | colonne nullable, ligne gardée, `down()` lève |

## Cas limites

- Un achat saisi à la main (`source` `manual`) compte comme un prix payé ; c'est la déclaration du propriétaire, approuvée par lui comme tout apport qu'il revoit.
- Le propriétaire qui lie un job et déclare aussi une installation sur la même ligne compte deux fois : à lui d'éviter, #318 et #340 règlent la détection et la revue.
- Un apport matériau d'une ligne de job (composant acheté) est valorisé comme un matériau ; le plafond de quantité par ligne reste hors périmètre.
- Les jobs et achats des participants autres que le propriétaire restent des apports déclarés (R8).
- Prix Jita lu hors cache de marché indisponible : même 400 que E3, jamais de dépôt à 0.

## Accès et fraîcheur

- Aucune route nouvelle. Dépôt : participant `accepted` (`SubmitContributionProcessor`), inchangé ; liens, suppressions, `match-jobs` : propriétaire seul (`assertOwner`), inchangé.
- Les apports dérivés n'existent que pour le participant propriétaire de l'instant : le transfert de propriété (R5) retire ceux de l'ancien. Les participants lisent déjà jobs et achats liés par la BOM : la valeur d'un apport dérivé n'ajoute aucune donnée exposée.
- Prix Jita : donnée publique du marché, lue au dépôt (fraîcheur du cache de `JitaMarketService`), jamais fournie par le client.

## Tests existants contredits ou modifiés

- `tests/Unit/Service/Industry/Project/ProjectContributionServiceTest` : valeur par prix BOM, valeur saisie sur matériau, construction (nouvelle dépendance).
- `tests/Unit/State/Processor/Industry/Project/SubmitContributionProcessorTest`, `SubmitSoloContributionProcessorTest` : valeur 5 500 par prix BOM, corps avec `estimatedValue`.
- `ProjectDistributionServiceTest`, `ProjectDistributionServiceUndistributableTest`, `ProjectDistributionProviderTest` (Unit et Integration) : types de retour nullables, cas `null` ajoutés.
- `tests/Unit/State/Processor/Industry/{CreatePurchase,DeletePurchase,LinkJob,UnlinkJob,MatchJobs}ProcessorTest`, `IndustryJobSyncServiceTest`, `AccountDeletionServiceTest` : nouveau service dérivé dans le constructeur ou le harnais.
- `frontend/tests/components/group-industry/contributeModal{BomPriceSource,UnknownMaterialPrice,JobInstallValue}.spec.ts` : libellé et corps du matériau (R9).

## Hors périmètre

Détection des jobs des autres participants (#318), revue limitée aux apports déclarés (#340), cascade revenu → profit et seuil (#346), plafond de quantité par ligne et apports partiels, revalorisation des apports existants, rafraîchissement du prix BOM, lecture du wallet pour un stock déjà possédé, kit BPC (#384), apport BPC par invention.

## Glossaire (à ajouter à l'implémentation)

- **Prix du jour d'apport** (Deposit-day price) : prix percentile 5 % Jita lu à la soumission d'un apport matériau, figé sur l'apport. Remplace le **prix BOM** comme valeur d'apport ; `IndustryProjectBomLine::$estimatedPrice` ne sert plus qu'à l'estimation de la BOM.
- **Apport dérivé** (Derived contribution) : apport du propriétaire calculé par `ProjectContributionService` depuis les achats liés (prix payé) ou les jobs liés (coût ESI) d'une ligne ; approuvé d'office, `isAutoDetected`.
- **Contribution** : « valeur = prix payé, coût ESI ou prix du jour d'apport ; inconnue (`null`) si le prix l'est ».
