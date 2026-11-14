# FAQ : compte et personnages

## Pourquoi EVE Tools demande-t-il 31 scopes ESI ?

Un scope est une permission que CCP vous demande d'accorder. EVE Tools réclame les 31 scopes de sa liste dans une seule autorisation EVE SSO, à la connexion et à chaque ajout de personnage. La plupart sont des lectures (inventaire, wallet, jobs, blueprints, compétences, marché). Deux agissent dans l'interface du jeu : ouvrir une fenêtre et définir la destination.

Six scopes sont demandés à l'avance : aucune fonction ne lit encore la file d'entraînement, la position, l'état en ligne, la flotte, les notifications du jeu ni les killmails.

Le tableau complet, scope par scope, est dans la page [Scopes ESI](/help?page=data/esi-scopes). Il est généré depuis le code.

## Comment ajouter un alt ?

Menu **Personnages**, bouton **Ajouter un personnage**. Vous passez par EVE SSO pour le nouveau personnage, qui est rattaché à votre compte. Une synchronisation de ses assets démarre en arrière-plan.

## Comment changer de personnage principal ?

Sur la carte d'un alt, dans **Personnages**, cliquez sur **Définir comme principal**. L'ancien principal reste dans votre compte comme alt.

Le principal compte à plusieurs endroits :

- c'est le seul de vos personnages que les autres participants d'un projet de groupe voient ;
- la recherche de structures et la lecture de vos compétences et standings pour les frais de vente passent par lui ;
- il ne peut pas être retiré : désignez-en un autre avant de retirer l'ancien.

## Que se passe-t-il quand un jeton expire ?

Le jeton d'accès est renouvelé tout seul avec le jeton de rafraîchissement : vous n'avez rien à faire.

Quand EVE SSO refuse le renouvellement (vous avez révoqué l'application côté CCP, ou le jeton de rafraîchissement a expiré), le personnage passe en **Token expiré**. Il sort seul des synchronisations, sans toucher aux autres personnages de votre compte. Cliquez sur **Re-autoriser** sur sa carte pour le reconnecter par EVE SSO : il redevient valide.

Le badge **Scopes manquants** signale un autre cas : le jeton est valide mais ne couvre pas tous les scopes requis. Re-autoriser règle aussi ce cas.

## Mes données sont-elles visibles par ma corporation ? Par les membres d'un projet de groupe ?

Votre inventaire, votre wallet, vos compétences, vos jobs et vos colonies sont rattachés à votre compte. Les endroits où quelque chose est partagé sont les suivants.

- **Structures de production** : une structure de votre corporation que vous importez devient disponible pour les autres membres de la corporation, avec sa configuration (type, sécurité, rigs). Chacun garde sa propre copie.
- **Inventaire de la corporation** : un directeur règle, division par division, ce qui est visible (tous les membres, une liste de joueurs, ou masqué). Les blueprints de la corporation peuvent rester partagés même pour une division masquée : c'est un réglage du directeur, coché par défaut.
- **Modèles de projet** : un modèle peut être partagé avec la corporation de votre personnage principal.
- **Projets de groupe** : les participants voient les membres, les apports, les ventes et le fil d'activité du projet. Ils voient votre personnage principal, jamais vos alts.

## Comment supprimer mon compte et mes données ?

Dans **Personnages**, section **Zone de danger**, bouton **Supprimer mon compte**. Vous confirmez en saisissant le nom exact de votre personnage principal. La suppression est immédiate et irréversible.

Sont supprimés : tous vos personnages, vos tokens EVE SSO (révoqués auprès de CCP), vos données personnelles (assets, compétences, jobs, transactions, PI, alertes, notifications, réglages) et vos projets industriels personnels.

Sont conservés et affichés sous le nom « Pilote supprimé » : vos apports approuvés aux projets de groupe des autres et les ventes que vous y avez enregistrées. Vos contributions non approuvées sont supprimées.

Un projet de groupe dont vous êtes propriétaire est transféré à son plus ancien admin, sinon à son plus ancien membre, sinon supprimé.
