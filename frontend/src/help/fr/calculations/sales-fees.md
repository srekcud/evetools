# Frais de vente : courtage et taxe de vente

EVE Tools retire deux frais d'une vente au marché : le **courtage** (frais de courtage, *broker fee*) et la **taxe de vente** (*sales tax*). Ce sont les **frais du vendeur** du glossaire. Ils dépendent des compétences et des standings du personnage principal du vendeur. Quand l'une de ces données manque, les frais sont **inconnus**, jamais 0 et jamais un taux de repli.

Les marges et les profits de [Profit et marge](/help?page=calculations/profit-and-margin) reprennent ces frais : un frais inconnu rend la marge inconnue.

## La formule

Les deux taux se calculent séparément, à partir de quatre données :

| Donnée | Rôle |
|---|---|
| Niveau d'**Accounting** (0 à 5) | Réduit la taxe de vente |
| Niveau de **Broker Relations** (0 à 5) | Réduit le courtage |
| **Standing** envers la faction **Caldari State** (de -10 à 10) | Réduit le courtage |
| **Standing** envers la corporation **Caldari Navy** (de -10 à 10) | Réduit le courtage |

Caldari State et Caldari Navy sont la faction et la corporation propriétaires de Jita 4-4 : ce sont les seuls standings lus.

```
taxe de vente = 7,5 % × (1 − 11 % × niveau d'Accounting)

courtage      = 3 %
                − 0,3 %  × niveau de Broker Relations
                − 0,03 % × standing envers Caldari State
                − 0,02 % × standing envers Caldari Navy
```

- Un niveau de compétence hors de 0 à 5 est refusé.
- Les standings sont pris tels que l'ESI les renvoie, sans calcul supplémentaire.
- Les formules sont linéaires et le code ne borne pas le résultat : un standing négatif augmente le courtage.
- Les coefficients (7,5 %, 11 %, 3 %, 0,3 %, 0,03 %, 0,02 %) sont des constantes du code, pas des données lues dans l'ESI ou dans le SDE. Si CCP les change en jeu, EVE Tools doit être mis à jour.

## D'où viennent les données

| Terme | Source | Fraîcheur |
|---|---|---|
| Niveau d'Accounting (compétence 16622) | ESI `GET /characters/{id}/skills/`, niveau actif, scope `esi-skills.read_skills.v1` | À la demande |
| Niveau de Broker Relations (compétence 3446) | Même appel | À la demande |
| Standing envers Caldari State (faction 500001) | ESI `GET /characters/{id}/standings/`, scope `esi-characters.read_standings.v1` | À la demande |
| Standing envers Caldari Navy (corporation 1000035) | Même appel | À la demande |
| Coefficients | Constantes du code | Changent avec une version d'EVE Tools |

**À la demande** : aucune synchro planifiée ne lit les compétences ni les standings. Ils sont relus seulement quand vous cliquez sur **Sync toutes** : **Industrie** › icône d'engrenage (**Configuration**) › section **Competences**. Un même clic synchronise les compétences et les standings de tous vos personnages. La colonne **Derniere sync** indique l'âge de la dernière lecture.

- Seul le **personnage principal** compte. Les compétences et standings de vos alts ne changent rien aux frais.
- Entre deux synchros, EVE Tools utilise les valeurs gardées en cache : un changement en jeu (compétence terminée, standing modifié) n'apparaît qu'à la synchro suivante.
- Si une synchro échoue (scope manquant, erreur ESI), les valeurs de la dernière synchro réussie restent utilisées. L'échec est signalé à la fin de la synchro, personnage par personnage.
- Après une synchro réussie, une compétence que le personnage n'a pas entraînée vaut le niveau 0 et un standing absent de la liste ESI vaut 0,0 (neutre). Ce sont des valeurs connues, pas des valeurs inconnues.

## Quels frais pour quelle vente

| Vente | Frais retirés |
|---|---|
| Ordre de vente à Jita ou dans une structure | Courtage + taxe de vente |
| Vente à un ordre d'achat existant (vente immédiate, ou ordre d'achat de la structure dans la comparaison des marges) | Taxe de vente seule |
| Contrat | Ni courtage ni taxe de vente |

- Dans la comparaison des marges, une vente par contrat est comptée à 0 de frais. Dans un projet, les frais réels du contrat sont lus dans le journal wallet du vendeur ; sans ligne dans le journal, ils sont inconnus.
- Pour une structure, EVE Tools applique les mêmes taux que pour Jita. Les frais propres que le propriétaire d'une structure peut fixer ne sont pas lus.
- Le calcul ne couvre ni les frais de modification d'un ordre ni les frais de relance.

## Exemple chiffré

Exemple de calcul, pas un personnage réel : Accounting 4, Broker Relations 3, standing 2,0 envers Caldari State, standing 1,0 envers Caldari Navy. Un ordre de vente rapporte 100 000 000 ISK brut pour un coût de production de 80 000 000 ISK.

| Étape | Calcul | Résultat |
|---|---|---|
| Taxe de vente | 7,5 % × (1 − 0,11 × 4) = 7,5 % × 0,56 | 4,2 % |
| Courtage | 3 % − 0,9 % − 0,06 % − 0,02 % | 2,02 % |
| Frais du vendeur | 4,2 % + 2,02 % | 6,22 %, soit 6 220 000 ISK |
| Revenu net | 100 000 000 − 6 220 000 | 93 780 000 ISK |
| Profit | 93 780 000 − 80 000 000 | 13 780 000 ISK |
| Marge | 13 780 000 ÷ 80 000 000 | environ 17,2 % |

La marge est un pourcentage du **coût**, pas du revenu. Le même ordre vendu à un ordre d'achat existant ne paierait que 4,2 % de taxe de vente, soit 4 200 000 ISK.

## Les frais d'un projet de groupe

Un projet garde ses propres frais, en pourcents :

- à la création, le projet copie les frais du propriétaire, calculés comme ci-dessus à cet instant ;
- une synchro faite après la création ne met pas le projet à jour : les frais copiés restent ceux de la création ;
- le propriétaire peut les saisir ou les corriger (champs **Frais courtier %** et **Taxe de vente %** du formulaire **Modifier le projet**) ; chaque champ va de 0 à 100 et leur somme ne peut pas dépasser 100 ;
- un projet terminé ne change plus de frais.

Le revenu net, la marge, les payouts et le seuil de rentabilité d'un projet utilisent les deux pourcentages du projet, pas les compétences de l'utilisateur qui regarde.

## Quand les frais sont inconnus

Les frais du vendeur sont inconnus dans trois cas :

| Donnée manquante | Cas |
|---|---|
| Personnage principal | Le compte n'en a pas |
| Compétences | Accounting ou Broker Relations absent du cache : aucune synchro des compétences n'a réussi pour le personnage principal |
| Standings | Aucune synchro des standings n'a réussi pour le personnage principal (scope `esi-characters.read_standings.v1` manquant, par exemple) |

Pour sortir de l'inconnu : vérifiez que le personnage principal a accordé les deux scopes, puis cliquez sur **Sync toutes**.

Ce que l'écran montre :

- **Quoi produire** : le message « Frais du vendeur inconnus : synchronisez les compétences et standings de votre personnage principal » s'affiche ; un produit dont le profit est inconnu est écarté du classement et compté dans « {n} produits écartés : données manquantes ».
- **Onglet Marges** (comparaison des lieux de vente d'un produit) : l'en-tête de la comparaison affiche « Frais inconnus », les colonnes des frais, du profit et de la marge affichent « — » (infobulle « Valeur inconnue ») et un bandeau explique qu'il faut synchroniser compétences et standings. La ligne **contrat** reste calculée, à 0 de frais.
- **Projet de groupe** : le résumé financier affiche « Frais inconnus : le propriétaire du projet n'a pas synchronisé ses compétences et ses standings, le revenu net, le profit et la marge ne peuvent pas être calculés. » ; le seuil de rentabilité indique « Les frais de vente sont inconnus. ». Un projet vendu uniquement par contrat n'a aucun frais de marché, donc rien d'inconnu de ce côté.
- **Formulaire Modifier le projet** : une note rappelle que, laissés vides, les frais restent inconnus ; les saisir à la main les rend connus sans synchro.

Les frais sont aussi inconnus quand une synchro n'a jamais été faite : un compte qui n'a jamais cliqué sur **Sync toutes** voit « inconnu » partout où une marge dépend des frais.
