# FAQ : des chiffres qui semblent faux

## Pourquoi « inconnu » à la place d'un prix ou d'une marge ?

EVE Tools n'invente jamais un chiffre. Quand une donnée manque, le résultat qui en dépend est affiché comme inconnu, jamais comme 0 : un 0 aurait l'air d'un vrai résultat et vous ferait décider sur une valeur fausse. Une somme qui contient un terme inconnu est elle-même inconnue.

Les causes les plus courantes :

- un prix de marché, un prix ajusté ou un cost index absent (la synchronisation concernée n'est pas encore passée ou a échoué) ;
- une probabilité d'invention inconnue, parce qu'une compétence du personnage inventeur n'est pas en cache ;
- des frais de vente inconnus : synchronisez les compétences et les standings de votre personnage principal ;
- un apport dont le prix est inconnu, ou un job dont l'ESI ne donne pas de coût.

Dans un projet de groupe, la marge et la part de chaque membre restent aussi inconnues tant qu'aucune vente n'est enregistrée (état « en attente des ventes ») et quand le coût total des apports approuvés vaut 0 (répartition impossible). Les coûts engagés restent calculés dans les deux cas.

## Pourquoi mon inventaire n'est pas à jour ?

Les assets ne sont pas synchronisés à intervalle régulier : la synchronisation se fait à la demande. Elle démarre à la connexion et à l'ajout d'un personnage, et quand vous lancez un rafraîchissement depuis l'application. Les assets de la corporation se rafraîchissent aussi à la demande.

Un changement fait en jeu n'apparaît donc qu'après le prochain rafraîchissement. Les autres données (jobs, wallet, marché, colonies) ont leur propre fréquence : voir [Fréquences de synchronisation](/help?page=data/sync-frequencies).

## Pourquoi le coût d'un projet diffère-t-il de ce que j'ai payé en jeu ?

Le coût d'un projet est la somme de la valeur de ses apports, et la valeur d'un apport dépend de sa source :

- un achat lié au projet : le **prix payé** ;
- un job lié au projet : le **coût ESI** du job ;
- un matériau que vous déclarez apporter, sans achat lié : le **prix Jita du jour de l'apport** (percentile 5 %), figé au moment où vous le déclarez.

Un écart avec ce que vous avez payé en jeu vient en général d'un achat que vous n'avez pas lié au projet : le matériau est alors valorisé au prix Jita du jour. Un prix inconnu donne un apport de valeur inconnue, jamais 0.

## Pourquoi un job terminé n'apparaît-il pas tout de suite ?

Les jobs d'industrie sont synchronisés toutes les 30 minutes, à partir de ce que l'ESI renvoie. Un job qui vient de se terminer en jeu peut donc mettre un moment à apparaître comme terminé dans un projet.
