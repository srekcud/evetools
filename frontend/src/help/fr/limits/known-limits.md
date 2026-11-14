# Limites connues

Dernière revue : 2026-11-14. La page est relue à chaque version `V0.x`.

Cette page liste ce qu'EVE Tools ne fait pas, ou fait mal, aujourd'hui. Une limite n'y figure que si elle existe dans le code ou dans une issue ouverte. La page ne promet aucune correction.

Chaque ligne dit ce qui est limité, pourquoi, ce que vous pouvez faire, et l'issue ouverte liée. Un tiret dans la colonne « Issue » veut dire qu'aucune issue ouverte ne décrit cette limite à la date de revue.

Une règle vaut pour tout EVE Tools : quand une donnée manque, l'écran dit « inconnu ». Il ne met jamais 0 à la place. Les lignes marquées « un 0 qui n'en est pas un » ci-dessous sont les exceptions connues.

## Compte et synchros

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **Plus aucune synchro planifiée après 7 jours sans connexion.** | Les synchros planifiées des jobs, du wallet, des blueprints, des ordres de vente, des colonies PI, du PVE, du minage et d'Ansiblex ne retiennent que les comptes connectés par EVE SSO depuis moins de 7 jours et qui ont un personnage à l'autorisation valide. « Ajouter un personnage » ne compte pas comme une connexion. | Vous reconnecter. | - |
| **Les compétences et les standings ne se synchronisent jamais seuls.** | Aucune tâche planifiée ne les lit. Tant que le bouton n'a pas servi, les frais du vendeur sont inconnus et les niveaux d'un personnage s'affichent à 0 avec la source « Manuel » : c'est une absence de donnée, pas des niveaux mesurés. | Industrie, icône « Configuration », section « Competences », bouton « Sync toutes ». | - |
| **Cinq scopes demandés ne servent à rien.** | La localisation, le statut en ligne, la flotte, les notifications EVE et les killmails sont demandés à chaque connexion, mais aucun code ne les lit. Tous les scopes sont demandés ensemble, sans choix scope par scope. | Aucun. Tableau scope par scope : [fraîcheur et confidentialité](/help?page=data/freshness-privacy). | - |
| **Le badge « Token valide » ne détecte pas une autorisation révoquée.** | Il ne regarde que la présence des scopes et le déchiffrement du jeton. | L'Accueil signale « personnage à reconnecter ». Voir la [FAQ](/help?page=faq/faq). | - |
| **Un 0 qui n'en est pas un : « ISK total » de l'Accueil.** | Le total additionne les soldes lisibles. Si aucun solde n'est lisible, il vaut 0, accompagné de « Total partiel : N soldes inconnus ». | Lire la mention « Total partiel », et la page Personnages pour le détail par personnage. | - |
| **« Retirer le personnage » ne révoque pas l'autorisation chez CCP.** | EVE Tools supprime son jeton, sans appeler EVE SSO pour le révoquer. | Révoquer l'application dans les applications autorisées de votre compte EVE Online. | - |
| **Une synchro très longue retarde toutes les autres.** | La production n'a qu'un seul worker, qui consomme la file asynchrone puis les tâches planifiées, et chaque tâche planifiée passe par cette file. L'issue rapporte des synchros planifiées en retard pendant des heures. | Aucun côté joueur. | #838 |
| **Le cache des réponses ESI peut ne pas s'écrire en production.** | Le Redis de production tourne avec une politique d'éviction que l'adaptateur de cache à étiquettes de l'ESI ne supporte pas. D'après l'issue, aucune réponse n'est alors mise en cache : chaque synchro relit tout et consomme le budget de requêtes de l'ESI, et les délais de cache décrits dans [fraîcheur et confidentialité](/help?page=data/freshness-privacy) ne jouent pas. | Aucun côté joueur. | #840 |

## Inventaire

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **Les assets ne se mettent pas à jour seuls.** | Aucune tâche planifiée ne les lit. Ils sont lus à votre connexion, à l'ajout d'un personnage et par le bouton « Actualiser », qui exige un personnage dont l'autorisation est valide. | Le bouton « Actualiser » de l'Inventaire. | - |

## Industrie

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **ME et TE par défaut différents selon l'écran.** | « Marges » part de ME 10 et TE 20 en T1, et prend ME 2 et TE 4 en T2, champs grisés (le décrypteur choisi les modifie). « Achat vs Prod » part de ME 2, sans champ TE. « Scan » suppose ME 10 et TE 20 en T1, un seul run, et ignore les bonus de matériaux de la structure et des rigs. La création d'un projet part de ME 2 et TE 4. | Vérifier le ME et le TE de chaque écran avant de comparer deux chiffres. Détail : [coût d'un job](/help?page=calculations/job-cost). | - |
| **Les frais d'un projet sont copiés à la création et jamais recalculés.** | Courtage et taxe de vente viennent de vos frais du vendeur au moment de la création. Si les compétences n'étaient pas synchronisées, ils restent vides, donc inconnus, même après une synchro. | « Modifier le projet », champs « Frais courtier % » et « Taxe de vente % ». Voir [frais de marché](/help?page=calculations/market-fees). | - |
| **Un 0 qui n'en est pas un : la carte « Bilan des 30 derniers jours » de l'Accueil.** | Les lignes « Industrie » et « Total » n'additionnent que les projets terminés à profit connu. Un projet à profit inconnu n'est pas compté, et la ligne vaut 0 si tous le sont. | L'onglet « Industrie » du Bilan indique « dont N à profit inconnu, non comptés ». | - |

## Projets de groupe

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **La vente d'un autre participant est comptée chez celui qui l'enregistre.** | Seuls le propriétaire et les admins enregistrent une vente, et le « Vendeur » ne peut être qu'un de leurs personnages. Le plan de virements part du principe que chacun garde le revenu net de ses ventes : la part d'un autre participant est comptée chez l'enregistreur. | Aucun réglage. Corrigez le virement concerné entre vous. Voir [contributions de groupe](/help?page=calculations/group-contributions). | - |
| **Courtage et taxe de vente s'appliquent à toute vente au marché d'un projet.** | Un projet applique les deux taux à tout le revenu du marché, y compris une vente à un ordre d'achat existant, alors que « Marges » ne compte que la taxe pour une « vente instantanee ». La marge d'un projet est donc plus basse que celle de l'analyse pour la même vente. | Aucun. Voir [frais de marché](/help?page=calculations/market-fees). | - |
| **Aucune notification d'acceptation, d'approbation ni de rejet.** | L'écran « Demande envoyée » écrit « Vous serez notifié dès qu'elle sera acceptée », mais EVE Tools n'envoie aucune notification à l'acceptation d'une demande, ni à l'approbation ou au rejet d'une contribution. La page ouverte se met à jour toute seule. | Prévenir la personne, ou revenir voir. Le « Journal » du projet garde « Apport approuvé » et « Apport rejeté ». | - |
| **Une demande d'adhésion en attente est invisible du demandeur.** | Le projet n'apparaît dans « Mes projets » qu'une fois la demande acceptée. Rouvrir le lien ramène à la liste des projets, sans message. | Demander au propriétaire. | - |
| **Une contribution en attente ne se retire pas, et un rejet n'a pas de motif.** | Il n'existe ni suppression ni annulation côté participant, et la revue ne transmet que « approuvée » ou « rejetée ». | Demander à un relecteur de la rejeter, puis la soumettre de nouveau. Dire le motif en dehors d'EVE Tools. | - |

## Marché

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **Le carnet d'ordres ne garde que 20 ordres par côté.** | Jita comme les structures ne conservent que les 20 meilleurs ordres de chaque côté. Le rang d'un de vos ordres de vente est plafonné à 21 : « #21 » se lit « 21 ou davantage ». | Aucun. Voir [ordres de marché](/help?page=playbooks/market-orders). | - |
| **Le carnet Jita est lu sur la première page de résultats de l'ESI.** | Pour un produit qui a plus d'une page d'ordres dans The Forge, le client ne lit pas les pages suivantes : le meilleur prix affiché peut manquer un ordre. | Vérifier en jeu avant un gros achat ou une grosse vente. | - |
| **Enregistrer une structure de marché ne lance aucune lecture.** | Le carnet arrive à la prochaine synchro, toutes les heures, et il faut qu'un joueur qui la suit ait le scope de marché de structure. Avant cela, vos ordres dans cette structure sont « Marché non suivi ». | Attendre la synchro suivante, jusqu'à une heure. | - |
| **« Sell pondere » d'une structure n'est pas pondéré.** | Dans l'Évaluateur, le prix de la structure est celui de l'ordre de vente le moins cher, multiplié par la quantité. | Voir [prix pondéré et évaluation](/help?page=calculations/weighted-price-appraisal). | - |
| **Un 0 qui n'en est pas un : les totaux de l'Évaluateur.** | « Valeur Sell », « Valeur Buy » et la colonne structure n'additionnent que les lignes qui ont un prix, sans le signaler. Si la lecture des prix échoue, un message s'affiche et les cartes valent 0. | Vérifier qu'aucune ligne n'affiche « - » avant de vous fier à un total. | - |

## Colonies PI

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **Un 0 qui n'en est pas un : le total de production PI.** | Un produit sans prix Jita compte pour 0 ISK dans le total. Il s'affiche « - », comme un produit entièrement consommé. | Lire le total comme un minimum. Voir [colonies planétaires](/help?page=playbooks/planetary-colonies). | - |
| **Le panneau « Production theorique / jour » d'une colonie ne retire pas la consommation.** | Il compte les usines de la colonie à plein régime, sans retirer ce qu'elles consomment, alors que le total global ne compte que le surplus. La somme des colonies ne retombe donc pas sur le total. | Se fier au total global pour la part vendable. | - |

## Minage

| Limite | Pourquoi | Contournement | Issue |
|---|---|---|---|
| **Un don ne paie un relevé de taxe lunaire que par corporation, payeur et date.** | Le motif du don n'est jamais lu, alors que « Comment payer » demande la référence du relevé en motif. Un don paie un relevé de la même corporation, du même payeur (le personnage auquel un gestionnaire l'a attribué, sinon le donateur), à partir du début de la période. | Payer depuis le personnage qui a miné, ou demander au gestionnaire d'attribuer le don. Voir [valeur du minerai et taxe lunaire](/help?page=calculations/ore-value-moon-tax). | - |
| **« Ouverte à l'alliance » ne filtre pas par alliance.** | C'est l'interrupteur qui fait lire la raffinerie. Tous les mineurs de son ledger sont pris, et « Hors alliance » n'est qu'un drapeau d'affichage sans effet sur le montant. | Aucun. Voir [minage et taxe lunaire](/help?page=playbooks/mining-moon-tax). | - |
| **Changer la période d'une raffinerie qui a déjà des relevés peut créer un doublon.** | Le code ne compare pas aux relevés d'un autre découpage : les mêmes jours de minage peuvent produire un second relevé. | Choisir la période avant la première synchro. | - |

## Pour aller plus loin

- [FAQ](/help?page=faq/faq) : scopes, « à reconnecter », « plus rien ne se met à jour ».
- [Fraîcheur et confidentialité des données](/help?page=data/freshness-privacy) : ce qui est synchronisé, quand, et ce qui est conservé.
- [Coût d'un job](/help?page=calculations/job-cost), [frais de marché](/help?page=calculations/market-fees), [marge](/help?page=calculations/margin) : les chiffres d'Industrie.
- [Contributions de groupe](/help?page=calculations/group-contributions) : valeur des apports et virements.
