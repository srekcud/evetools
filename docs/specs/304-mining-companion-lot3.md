# #304 lot 3 : compagnon hors opération (cible libre)

Front seul (aucune route API, migration ni entité). Suite de `304-mining-companion-lot1.md` et `-lot2.md` (PR #515, #518, #522 fusionnées). Décision de Sylvain (2026-11-10, « lance ça » après le Blocage du journal) : option A, cible libre détachable depuis la liste des opérations. Prérequis vérifiés sur origin/main : `useCorporationMiningExtractions` (`load`, `corporations[].extractions`, `chunkCountdown`, `moonChunkStatusAt`), `NewMiningOperationModal` (prop `chunk`, envoie `chunkId`), `miningSite.create({label, solarSystemId, operationId?})`, `MiningCompanionNextSite`, `MiningCompanionPanel` (état vide), `MiningOperationList`.

## Périmètre

Livré : une cible libre `{ siteId: null, operationId: null }` du compagnon avec le prochain chunk et son compte à rebours, « Créer l'opération » (#274), « Estimer un site seul » (#267). Hors lot : valeur du chunk, suivi des raffineries, tout changement de `NewMiningOperationModal`, du ledger ou de l'API. L'issue #304 est complète avec ce lot : `Closes #304` si la PR passe les gates.

## Règles

1. **Entrée** : bouton `operation-list-companion` (libellé `miningCompanion.detachFree`, titre `miningCompanion.detachFreeTitle`) dans l'en-tête de `MiningOperationList` : `companion.detach({ siteId: null, operationId: null })`. Bouton existant « Nouvelle opération » inchangé.
2. **Cible libre** : `target.siteId === null && target.operationId === null`. L'hôte la distingue de « fermé » (`target === null`). Elle n'appelle ni `readOperation` ni `readOperationSites` ; elle charge `useCorporationMiningExtractions().load()` (à l'ouverture, puis sur la sync terminée déjà gérée par le composable ; pas de rechargement au retour du focus).
3. **Prochain chunk** (fonction pure `nextChunkOf(corporations, now)` dans `views/MiningSite/miningCompanion.ts`) : parmi les extractions de toutes les corporations dont `moonChunkStatusAt` n'est pas `exploded`, celle dont `chunkArrivalTime` est la plus ancienne ; `null` si aucune. Égalité : ordre d'entrée.
4. **Contenu libre** (composant de présentation `MiningCompanionFree`, props `chunk: CorporationMiningExtraction | null`, `now: Date`, `loading: boolean`, `failure: string | null`, `estimating: boolean`, `estimateFailure: string | null` ; événements `create-operation` (id du chunk), `estimate` ({label, solarSystemId}), plus un slot ou état interne pour le formulaire) :
   - test-ids : `companion-free`, `companion-free-chunk`, `companion-free-countdown`, `companion-free-create-operation`, `companion-free-estimate`, `companion-free-none`, `companion-free-error` ;
   - chunk connu : nom de lune (`moonName`, sinon l'identifiant `moonId`, jamais vide), système (`solarSystemName`, sinon `UNKNOWN_DASH`), état (`extracting` ou `ready`), compte à rebours `chunkCountdown(chunk, now)` ;
   - aucun chunk : message `miningCompanion.free.none` et « Estimer un site seul » reste disponible ;
   - chargement : `miningCompanion.loading` ; échec : message dans `companion-free-error`, pas de chunk affiché.
5. **« Créer l'opération »** : bouton visible si un chunk est affiché. Il ne crée rien dans la fenêtre détachée (`BaseModal` se téléporte dans la fenêtre principale) : l'hôte fait `window.focus()` puis `router.push({ name: 'mining-operations', query: { chunk: <id> } })`.
6. **`MiningOperationList` avec `?chunk=<id>`** : après le chargement des listes, charge les extractions (`useCorporationMiningExtractions().load()`), cherche l'extraction par id : trouvée et non `exploded` : ouvre `NewMiningOperationModal` avec `chunk`; introuvable ou explosée : message `miningOperation.list.chunkUnavailable` (role `status`, test-id `operation-list-chunk-unavailable`), pas de modale. Dans tous les cas la requête `chunk` est retirée de l'URL (`router.replace` sans `chunk`, autres paramètres conservés). Sans `chunk`, aucun chargement d'extractions.
7. **« Estimer un site seul »** : bouton `companion-free-estimate` ouvre `MiningCompanionNextSite` (nouvelle prop optionnelle `closesActive`, défaut `true` : l'avertissement `miningSite.create.closesActive` s'affiche seulement quand elle vaut `true`). Soumission : `siteStore.create({ label, solarSystemId, operationId: undefined })` ; succès : `companion.retarget({ siteId: created.id, operationId: null })` (le compagnon montre le site libre, collage possible) ; échec : message du serveur dans le formulaire resté ouvert.
8. **« Poste »** d'une cible libre : `window.focus()` puis `router.push({ name: 'mining-operations' })` (aujourd'hui sans effet).
9. **Taille** : le contenu libre s'affiche en `compact` et `detail` ; en `bar` : nom de lune et compte à rebours seuls (pas de boutons, comme le pied du panneau).
10. **Aucun son**, donnée inconnue « inconnue » ou `UNKNOWN_DASH`, jamais 0. Chaînes sous `miningCompanion.free.*`, `miningCompanion.detachFree*`, `miningOperation.list.chunkUnavailable` en `en.json` et `fr.json`.

## Exemples chiffrés

| Cas | Attendu |
|---|---|
| extractions : A arrive dans 3 h, B arrive dans 1 h, C explosée | `nextChunkOf` = B |
| aucune extraction non explosée | `nextChunkOf` = `null`, message « aucun chunk » |
| chunk B à 1 h 05 de l'arrivée | countdown « 1h 05m » (`chunkCountdown`) |
| `moonName` null, `moonId` 40009001 | affiche « 40009001 » |
| clic « Créer l'opération » sur B | `router.push({name:'mining-operations', query:{chunk:B.id}})` |
| liste ouverte avec `?chunk=B.id`, B non explosé | modale ouverte avec B, `chunk` retiré de l'URL |
| liste ouverte avec `?chunk=inconnu` | pas de modale, message « chunk indisponible », `chunk` retiré |
| « Estimer un site seul » « Belt Jaspet » | `POST /mining-sites` `{label:'Belt Jaspet', solarSystemId:null}`, `target.siteId` = id créé |

## Cas limites

Extractions en échec de chargement : pas de chunk inventé, message d'erreur. Chunk qui explose pendant que le compagnon est ouvert : le prochain est recalculé à chaque tick (`now`). Deux corporations avec un chunk à la même heure : premier de l'ordre d'entrée.

## Accès et fraîcheur

Aucun contrôle nouveau : les extractions sont celles que l'API renvoie à l'utilisateur connecté (`/mining/corporation-extractions`), l'identifiant `chunk` d'URL n'est jamais envoyé tel quel : seule une extraction trouvée dans cette réponse alimente la modale (donc `chunkId` valide pour l'utilisateur), l'API revalide à la création (`MiningOperationChunkResolver`).

## Décisions prises en autonomie

(1) « Créer l'opération » passe par la fenêtre principale (la modale ne peut pas vivre dans la fenêtre détachée). (2) Le prochain chunk est le plus proche à arriver parmi les non explosés (un chunk `ready` passe avant un chunk à venir : il est minable maintenant). (3) Pas de rechargement périodique des extractions : la sync existante via Mercure les met à jour.

## Tests existants contredits ou modifiés

Aucun. `MiningCompanionNextSite.spec.ts` reste vrai (la prop `closesActive` vaut `true` par défaut).

## Glossaire

Ajout : **Cible libre** (compagnon de minage sans site ni opération : prochain chunk, création d'opération, estimation d'un site seul).
