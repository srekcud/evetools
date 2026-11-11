# #627 — Acquitter les lignes de la section « À faire » du Pont

Spec rédigée en mode nuit, sans gate. Les trois points « à trancher » suivent le premier commentaire de l'issue (recommandations de Sylvain).

## Contexte

La section « À faire » du Pont (`useTodoItems.ts`, `Dashboard.vue`) recalcule ses lignes à chaque chargement : `charactersToReconnect` / `atLeastCharactersToReconnect` (critical), `expiredExtractors` (critical), `expiringExtractors` (warning), `triggeredPriceAlerts` (info), plus des lignes « inconnu » quand une source échoue. Rien ne permet de retirer une ligne dont la cause durera (colonie PI laissée à l'arrêt, alerte déclenchée gardée).

## Règles

1. **Acquittable** : seules `expiredExtractors`, `expiringExtractors`, `triggeredPriceAlerts`. Non acquittables : les lignes personnages (critical, les syncs échouent tant que non reconnectés) et toutes les lignes « inconnu » (compteur `null`).
2. **Granularité** : par ligne agrégée. On stocke la clé de la ligne (`itemKey`) et son compteur au moment de l'acquittement (`acknowledgedCount`).
3. **Masquage** : une ligne acquittable est masquée tant que `compteur courant <= acknowledgedCount`. Elle réapparaît dès que le compteur courant dépasse le compteur acquitté.
4. **Retour à zéro** : quand le compteur courant d'une source chargée vaut 0 et qu'un acquittement existe pour sa clé, le client le supprime (`DELETE`). Une cause qui revient réaffiche donc la ligne. Source en échec ou en chargement : aucun acquittement supprimé.
5. **Stockage serveur, par utilisateur** : partagé entre navigateurs et appareils. Un utilisateur ne lit, n'écrit et ne supprime que ses propres acquittements.
6. **Aucun effet sur la donnée source** : l'alerte reste `triggered`, la colonie reste arrêtée sur la page PI. Seul le compteur de `GET /planetary/stats` et la liste de `GET /market/alerts` sont lus, comme aujourd'hui.
7. **Pas d'expiration** : l'acquittement vit jusqu'au retour à 0 ou jusqu'à une hausse du compteur.
8. **Re-acquittement** : acquitter une clé déjà acquittée remplace le compteur (upsert), jamais de doublon.
9. **Tout acquitter** : un bouton acquitte toutes les lignes acquittables visibles d'un coup ; il n'apparaît que s'il en existe au moins une.
10. **Acquittements illisibles** (échec de `GET`) : aucune ligne n'est masquée (on préfère trop afficher que cacher) et la section reste utilisable. Un échec d'écriture laisse la ligne visible et est journalisé en console, jamais silencieux sur la donnée.
11. **« All caught up »** : s'affiche quand il ne reste aucune ligne visible une fois les trois sources et les acquittements chargés. Pendant le chargement des acquittements, le spinner reste affiché (pas de flash de lignes qui disparaissent).

## API (API Platform, `shortName: BridgeAcknowledgement`, `ROLE_USER`)

| Opération | URI | Corps / réponse |
|---|---|---|
| GetCollection | `GET /me/bridge/acknowledgements` | `[{itemKey, acknowledgedCount}]` de l'utilisateur courant |
| Get (résolution d'IRI) | `GET /me/bridge/acknowledgements/{itemKey}` | 404 si absent |
| Post | `POST /me/bridge/acknowledgements` | `{itemKey, count}` → upsert, répond la ressource |
| Delete | `DELETE /me/bridge/acknowledgements/{itemKey}` | provider qui renvoie la ressource, 404 si absent |

- Identifiant de la ressource : `itemKey` (`#[ApiProperty(identifier: true)]`).
- Validation (422) : `itemKey` dans {`expiredExtractors`, `expiringExtractors`, `triggeredPriceAlerts`} ; `count` entier >= 1. Une clé personnage est refusée.
- Pas de topic Mercure : l'écriture est synchrone et un seul acquittement n'a pas à se propager en direct ; les autres navigateurs le voient au prochain chargement (voir Décisions du journal).

## Données

Entité `BridgeAcknowledgement` (table `bridge_acknowledgements`) : `id` (UUID), `user` (ManyToOne `User`, `onDelete: CASCADE`), `itemKey` (string, enum `BridgeTodoKey`), `acknowledgedCount` (int), `createdAt`, `updatedAt`. Contrainte unique (`user_id`, `item_key`). Une migration crée la table ; pas de donnée à migrer.

## Exemples chiffrés (ce seront les tests rouges)

| État serveur | Compteur courant | Ligne affichée ? |
|---|---|---|
| aucun acquittement | `expiredExtractors` = 2 | oui |
| `expiredExtractors` acquitté à 2 | 2 | non |
| `expiredExtractors` acquitté à 2 | 3 (nouvel extracteur arrêté) | oui, compteur 3 |
| `expiredExtractors` acquitté à 2 | 1 (deux sur trois remis en marche, un arrêté) | non |
| `expiredExtractors` acquitté à 2 | 0 | non ; `DELETE /me/bridge/acknowledgements/expiredExtractors` émis |
| `expiredExtractors` acquitté à 2 puis supprimé | 2 | oui |
| `triggeredPriceAlerts` acquitté à 1 | 1 alerte déclenchée | non ; l'alerte garde `status: triggered` |
| `expiringExtractors` acquitté, `expiredExtractors` non | 1 et 2 | seule la ligne expired reste |
| `GET /planetary/stats` en échec | n/a | ligne `unknownExtractors`, sans bouton d'acquittement, aucun `DELETE` |
| personnage à reconnecter | n/a | ligne sans bouton d'acquittement |
| 3 lignes acquittables visibles, clic « tout acquitter » | n/a | un `POST` par ligne avec son compteur courant, puis « All caught up » (personnages éventuels restent) |

Backend :

| Cas | Résultat |
|---|---|
| `POST {itemKey: expiredExtractors, count: 2}` | 201, ligne créée pour l'utilisateur |
| même `POST` avec `count: 5` | ligne mise à jour, toujours une seule |
| `itemKey: charactersToReconnect` ou `count: 0` | 422 |
| `GET` collection de A pendant que B a des acquittements | seuls ceux de A |
| `DELETE` sur la clé de B | 404 pour A, la ligne de B reste |
| suppression du compte | acquittements supprimés (cascade) |

## Accès et fraîcheur

Chaque provider et processor lit `Security::getUser()` et filtre sur l'utilisateur ; `itemKey` est la seule donnée reçue et ne désigne jamais l'objet d'un autre utilisateur. Aucune donnée de corporation. Le compteur acquitté est celui lu par le client au moment du clic ; la fraîcheur des sources est inchangée.

## Cas limites

- Deux onglets : le dernier `POST` gagne (upsert) ; l'onglet plus ancien affiche une ligne en trop jusqu'à rechargement.
- Compteur qui baisse sans atteindre 0 : l'acquittement garde son ancien compteur (la ligne ne revient qu'au-delà).
- `expiredExtractors` et `expiringExtractors` sont acquittés indépendamment.

## Tests existants modifiés

`frontend/tests/composables/todoSources.ts` (`sourcesAnswering` doit répondre aussi à `/me/bridge/acknowledgements`, vide par défaut) et les specs du Pont qui simulent `apiRequest` (`DashboardTodoSection`, `DashboardBridge`, `DashboardUnknownBalance`) : un appel de plus au chargement, sans changement de comportement attendu. `AccountDeletionServiceTest` : la table passe par la cascade, à vérifier sans modifier l'assertion.

## Hors périmètre

Acquittement des lignes personnages ; expiration de l'acquittement ; acquittement par élément (extracteur, alerte) ; temps réel Mercure ; page d'historique des acquittements ; changement de la page PI ou de la page des alertes.

## Glossaire

Ajout : **Acquittement** / Acknowledgement (voir `docs/glossary.md`).
