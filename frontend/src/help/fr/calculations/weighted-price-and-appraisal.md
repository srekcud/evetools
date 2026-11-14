# Prix pondéré et Évaluateur

La page **Évaluateur** (Appraisal) valorise au prix de Jita une liste d'items que vous collez (depuis le jeu ou au format nom + quantité). Elle donne deux totaux pour la même liste : ce que coûterait l'**achat** (ordres de vente) et ce que vous **recevriez en vendant** tout de suite (ordres d'achat). Les deux reposent sur le **prix pondéré** du carnet d'ordres de Jita.

Elle se trouve sous **Commerce** › **Évaluateur**.

## Le prix pondéré

Le « prix le plus bas » n'est valable que pour une unité. Le prix pondéré est le prix unitaire moyen que vous paieriez (ou recevriez) réellement pour toute la quantité : les ordres du **carnet d'ordres** sont consommés du meilleur au moins bon jusqu'à couvrir la quantité.

```
pour chaque ordre, du meilleur au moins bon :
    pris     = min(unités qui manquent, volume de l'ordre)
    coût    += pris × prix de l'ordre
    couvert += pris

prix unitaire pondéré = coût ÷ couvert
couverture            = couvert ÷ quantité demandée   (1 au plus)
total de la ligne     = prix unitaire pondéré × quantité demandée
```

- **Côté Sell** (lignes « Sell ») : les ordres de vente, du moins cher au plus cher. C'est le coût d'achat de la quantité.
- **Côté Buy** (lignes « Buy ») : les ordres d'achat, du plus élevé au plus bas. C'est ce que vous recevez en vendant la quantité immédiatement (**vente immédiate**).
- Le **meilleur prix** est le premier ordre du carnet : l'ordre de vente le moins cher, l'ordre d'achat le plus élevé. Il s'affiche en gris sous la valeur pondérée.
- Les prix et les totaux sont arrondis à 2 décimales.
- La **quantité demandée** est la somme des quantités collées pour l'item : deux lignes du même item sont fusionnées.

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Nom → item | SDE (`sde_inv_types`) : nom exact, sans tenir compte de la casse, items **publiés** seulement | Change à l'import du SDE |
| Ordres et leur volume | ESI `GET /markets/10000002/orders/` (The Forge), un appel par item, sans authentification | Synchro toutes les heures, puis 5 minutes (voir plus bas) |
| Ordres de Jita 4-4 | Ordres situés à la station Jita 4-4 ; si la station n'a **aucun** ordre d'un côté, tous les ordres de The Forge de ce côté. Le choix se fait côté par côté | Idem |
| Profondeur du carnet | Les 20 meilleurs ordres de chaque côté sont conservés | Idem |
| Volume unitaire (m³) | SDE | Change à l'import du SDE |
| Volume journalier moyen (`~N/jour`) | Historique de marché ESI de The Forge, moyenne des 30 dernières entrées de l'historique | Mis en cache 24 heures par item |

**Fraîcheur des ordres :**

- Une synchro planifiée lit les carnets de Jita **toutes les heures**. Elle couvre les items dont EVE Tools a besoin pour l'Industrie : matériaux des activités de fabrication, d'invention et de réaction, minerais et minerais compressés, sorties de retraitement, produits planétaires et produits fabricables. Le résultat est conservé **2 heures**.
- Un item **hors de cette liste**, ou n'importe quel item quand aucun résultat de synchro n'est disponible, est lu sur l'ESI au moment de l'évaluation et conservé **5 minutes**.
- La page Évaluateur n'affiche l'âge des prix de Jita que dans le panneau **Comparaison Structure** (ligne **Prix Jita**), une fois que les prix d'une structure sont synchronisés.

## Exemple chiffré

Chiffres illustratifs, pas des prix réels du marché. Un item dont les ordres de vente à Jita sont : 400 unités à 100 ISK, 300 à 110 ISK, 200 à 120 ISK (900 unités au total).

**Quantité 600 : le carnet suffit.**

| Étape | Calcul | Résultat |
|---|---|---|
| Unités prises | 400 à 100 + 200 à 110 | 600 sur 600 |
| Coût | 40 000 + 22 000 | 62 000 ISK |
| Prix unitaire pondéré | 62 000 ÷ 600 | environ 103,33 ISK |
| Couverture | 600 ÷ 600 | 100 %, aucun marqueur |
| Meilleur prix (ordre le plus bas) | 100 × 600 | 60 000 ISK |

**Quantité 1 000 : le carnet est trop court.**

| Étape | Calcul | Résultat |
|---|---|---|
| Unités prises | 400 + 300 + 200 | 900 sur 1 000 |
| Coût de la part couverte | 40 000 + 33 000 + 24 000 | 97 000 ISK |
| Prix unitaire pondéré | 97 000 ÷ 900 | environ 107,78 ISK |
| Couverture | 900 ÷ 1 000 | 90 %, marqueur « 90 % couvert » |
| Total de la ligne | 107,78 × **1 000** | environ 107 778 ISK |

Les 100 unités manquantes sont valorisées au prix moyen des unités couvertes, pas à un prix plus haut : le coût réel est probablement supérieur. C'est pour cela que le pourcentage est affiché.

Côté Buy, avec des ordres d'achat de 300 unités à 90 ISK puis 200 à 80 ISK et une quantité de 400 : 300 × 90 + 100 × 80 = 35 000 ISK, soit 87,50 ISK l'unité.

## Lire la page

- **Valeur Sell** (carte) : le total pondéré du côté Sell, « Cout pour acheter ces items ». **Valeur Buy** : le total pondéré du côté Buy, « Ce que vous recevez en vendant ». Sous chacune, **Meilleur prix** est le même total au meilleur prix seulement.
- Colonne **Prix unitaire** : le prix unitaire pondéré des lignes **Sell** et **Buy**, avec le meilleur prix en gris dessous, et `~N/jour` (en orange quand la quantité dépasse le volume journalier moyen).
- Colonne **Total pondere** : le total de ligne de chaque côté, avec le total au meilleur prix en gris.
- **Couverture** : quand un carnet est trop court, le total de la ligne porte « {n} % couvert » (affiché de 0,1 % à 99,9 %, jamais 0 % ni 100 %). Le prix unitaire Sell porte aussi un triangle d'avertissement : « Seulement {available} sur {total} disponibles (top 20 ordres) ».
- Au-dessus du tableau, le panneau **Comparaison Structure** compare Jita et une structure. Il utilise le **prix de vente le plus bas** de chaque marché, pas un prix pondéré : « Jita + Transport » est le prix de vente le plus bas de Jita × quantité plus volume × **Cout transport / m3** (1 200 ISK par défaut). La colonne de la structure est le prix de vente le plus bas de la structure × quantité, lu dans la dernière synchro de la structure. La colonne et son infobulle s'intitulent « Sell pondere », ce qui ne correspond pas à ce qui est calculé.

## Quand le résultat est inconnu

| Cas | Ce que la page affiche |
|---|---|
| Aucun ordre d'un côté (ou aucun volume dans le carnet), ou l'appel ESI de cet item a échoué | Le prix unitaire et le total de ce côté affichent « - ». L'item n'entre pas dans le total de ce côté. L'échec de l'appel d'un seul item ne donne aucun message d'erreur |
| Item non reconnu (nom inconnu ou non publié) | Le bandeau jaune « {count} item(s) non trouve(s) » liste les noms. Ils ne sont pas évalués |
| Item sans volume dans le SDE | « Inconnu » dans la colonne **Volume** et dans la carte **Volume total**, et un bandeau qui nomme les items. Le volume et le total Jita + Transport sont inconnus |
| Prix illisibles dans leur ensemble | Le bandeau « Unable to fetch market prices. Please try again later. » apparaît (en anglais dans les deux langues). Les items n'ont aucun prix |
| Aucun marché synchronisé pour la structure | La colonne de la structure affiche « -- » pour chaque item. Utilisez **Sync prix** dans le panneau |
| Volume journalier moyen indisponible | La ligne `~N/jour` n'est pas affichée. L'échec est journalisé et l'évaluation continue |

Trois points demandent de l'attention :

- **Les totaux n'additionnent que les items qui ont un prix.** Aucun compteur ne dit combien d'items sont laissés de côté : cherchez les « - » dans le tableau. Quand aucun item n'a de prix, la carte affiche 0 ISK : lisez-le comme « aucun prix », pas comme une valeur.
- Une ligne que le lecteur ne sait pas interpréter est **ignorée sans message** : elle n'est ni évaluée ni listée comme non trouvée. Par exemple une quantité avant le nom sans `x` (`1000 Tritanium`) est sautée. Les formes acceptées sont `10x Tritanium`, `Tritanium x10`, `Tritanium<tabulation>10`, un nom suivi de deux espaces et d'un nombre, et un nom seul (quantité 1). Les boutons **Format** ne changent pas la lecture : le texte envoyé est toujours le même.
- Une couverture de 100 % dit seulement que le carnet **connu** suffit. EVE Tools lit la première page des ordres ESI d'un item et garde 20 ordres par côté : sur un marché profond, les ordres au-delà sont inconnus et le meilleur prix peut être faux.
