# #318 : job lancé par un autre participant = apport dérivé à son nom

Périmètre fixé par le commentaire de Sylvain du 2026-11-09 : #391 (apports dérivés du propriétaire, spec #363) existe ; il reste (1) un job lancé par un autre participant devient un apport dérivé à son nom, (2) sa valeur est le coût réellement payé en jeu. L'ancien modèle de l'issue (contribution en attente, `isAutoDetected` à valider) ne s'applique plus. Le coût estimé avant lancement, les containers de jobs et la distribution (#325) sont hors périmètre.

## Règles

1. **Détection** : `IndustryJobMatcher::matchEsiJobs` cherche les jobs des personnages du propriétaire et de ceux des participants `accepted` (un participant `pending` ou `removed` n'est pas lu). Les autres règles de rapprochement (blueprint du producteur, démarré depuis la création du projet, sans job sans production, pas lié à un autre projet, runs qui tiennent dans le reste de la ligne) ne changent pas.
2. **Installateur d'un lien** : l'utilisateur dont un personnage porte `IndustryProjectJobMatch::getCharacterName()` (noms de personnages uniques) parmi les participants du projet. Nom absent ou inconnu : le propriétaire (comportement de #363, aucun défaut inventé : ce sont les liens créés avant cette règle).
3. **Apport dérivé installation de job** : `DerivedContributionService::refreshLine` crée, par ligne de job, un apport par participant installateur : quantité = somme des runs de ses liens, valeur = somme des coûts ESI de ses liens (`null` dès qu'un de ses liens n'a pas de coût, jamais 0), `approved` d'office, `isAutoDetected`, `isVerified`, au nom du participant. Les liens des autres n'entrent pas dans son total.
4. Un lien retiré ou un participant sans lien : son apport dérivé installation disparaît ; ceux des autres restent. Les apports dérivés matériau restent ceux du propriétaire seul ; la quantité livrée de la ligne ne bouge pas.
5. **Masquage** (#339) : `JobInstallerName::seenBy` rend le nom brut à l'installateur lui-même, le main de l'installateur à tout autre lecteur (propriétaire compris), et le main du propriétaire si l'installateur est inconnu (liens antérieurs).
6. Sans migration : l'installateur est dérivé du nom de personnage déjà stocké sur le lien.

## Exemple

Ligne Ishtar de 5 runs. Le propriétaire a un lien de 2 runs à 100 000 ISK, Sana Vel (participante acceptée) un lien de 3 runs à 214 000 ISK : deux apports dérivés, propriétaire (2 runs, 100 000), Sana (3 runs, 214 000). Si le coût du lien de Sana est inconnu, son apport vaut `null` et celui du propriétaire reste à 100 000.

## Cas limites

- Participant retiré ou parti : son apport reste jusqu'au prochain rapprochement, qui ne lit plus ses jobs puis supprime ses liens automatiques et donc son apport.
- Personnage sans scope industrie ou jeton expiré : ses jobs ne sont pas en cache à jour ; le coût d'un lien dont l'ESI n'a pas renvoyé de `cost` reste inconnu.
- Lien manuel (`LinkJobProcessor`, propriétaire) : inchangé, jobs du propriétaire seulement.

## Déclencheur (décision de Sylvain, 2026-11-09)

`IndustryJobSyncService::syncUserJobs` appelle `OpenProjectsJobMatcher::matchOpenProjectsOf` après la sync de tous les personnages de l'utilisateur : rapprochement et `refreshLine` des lignes de job de chaque projet non terminé où il est participant accepté. `syncCharacterJobs` (bouton du propriétaire) ne rapproche pas : le bouton le fait déjà pour son projet.

## Décisions ouvertes (hors lot)

- Voies du planning (`ProjectPlanningInputLoader::charactersOf`) : jobs des autres participants non montrés (une voie par personnage du propriétaire), suite de #339.
