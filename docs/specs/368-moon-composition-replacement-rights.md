# Spec #368 (lot 2) — Qui peut remplacer la composition d'une lune

**Statut : validée en autonomie en mode nuit le 2026-11-04, à relire par Sylvain.**

Lot `group:mining-ops`, circuit `circuit:m`, **niveau 3 (chemin d'accès)** : fusion locale seulement, push et fermeture à Sylvain. Ni migration ni topic Mercure (l'enregistrement reste synchrone, M11 de #308). Décision de Sylvain (2026-11-04, interactive) : « auteur ou rôle corporation ». Remplace la règle « tout membre » de M10 (#308) pour le seul remplacement.

## Règles

- **R1 — Premier collage.** Sans composition enregistrée pour `(corporationId, moonId)`, tout utilisateur qui passe déjà le contrôle de M10 (personnage de la corporation, extraction existante) peut enregistrer. Aucun appel ESI.
- **R2 — Remplacement par l'auteur.** Avec une composition existante, l'utilisateur qui l'a enregistrée (`recordedBy`) peut la remplacer. Aucun appel ESI.
- **R3 — Remplacement par un rôle.** Un autre utilisateur peut la remplacer si l'un de ses personnages **de cette corporation** (`corporationId` du personnage = celui du collage) porte le rôle ESI `Director` ou `Station_Manager` (`CharacterService::getCharacterRoles`, une seule lecture par personnage, scope `esi-characters.read_corporation_roles.v1` déjà demandé). Constantes nommées `Director` et `Station_Manager` (ce dernier est déjà le rôle de la synchro des extractions).
- **R4 — Refus.** Ni auteur ni rôle : **403**, message « Only the author of the scan or a Director or Station Manager of the corporation can replace it », rien n'est modifié. Un utilisateur sans personnage dans la corporation ou sur une lune sans extraction reste en 404 (M10 inchangé, évalué avant R1 à R3 : aucune fuite d'existence).
- **R5 — Auteur absent.** Composition dont `recordedBy` est `null` (utilisateur supprimé) : R2 ne s'applique à personne, seul R3 permet le remplacement.
- **R6 — Rôles illisibles, jamais un défaut.** Si la lecture des rôles d'un personnage échoue (`EsiApiException`, `EveAuthRequiredException`) et qu'aucun autre personnage de la corporation n'ouvre le droit : **503**, message « The corporation roles of your characters could not be read, try again later », rien n'est modifié. Ce n'est ni un 403 ni une autorisation. Un personnage sans jeton compte comme « aucun rôle » (comportement de `getCharacterRoles`).
- **R7 — Un seul contrôle.** La règle vit dans un service `MoonCompositionReplacementPolicy` (couche Application, `src/Service/MoonChunk/`), appelé par `RecordMoonCompositionProcessor` (`assertMayRecord`, exceptions `MoonCompositionReplacementRefused` et `CorporationRolesUnreadable`, traduites en 403 et 503) après le contrôle de portée et les bornes du scan, avant `MoonCompositionRecorder::record`. Les rôles ne sont lus que si ni R1 ni R2 ne décident : jamais d'appel ESI pour l'auteur ou le premier collage.
- **R8 — Course.** Le contrôle se fait avant le verrou du recorder ; deux collages simultanés restent sérialisés par le verrou existant (#308), le dernier gagne parmi des utilisateurs déjà autorisés. Hors périmètre : relire l'auteur sous le verrou.
- **R9 — Front.** Un 403 ou 503 du collage s'affiche dans la zone de collage (même emplacement que les erreurs 422, texte i18n `fr`/`en`, glyphe ⚠, jamais la couleur seule) ; le texte collé est conservé, les extractions ne sont pas rechargées. Le bouton « re-coller » reste visible pour tous (le serveur décide).

## Exemples

Harnais `DrivesMoonChunkApi` : `aria` (Ilyra, auteur des compositions semées), `brann` (Cyra) ; un troisième pilote `cole` de la corporation Ilyra, sans rôle.

| # | Cas | Attendu |
|---|---|---|
| E1 | `cole` colle pour une lune Ilyra sans composition | 201, enregistrée par `cole`, aucun appel de rôles |
| E2 | `aria` remplace sa composition | 201, une seule composition, aucun appel de rôles |
| E3 | `cole` (sans rôle) remplace la composition d'`aria` | 403, composition d'`aria` intacte |
| E4 | `cole` avec `Director` sur son personnage Ilyra | 201, composition remplacée, `recordedBy` = `cole` |
| E5 | `cole` avec `Station_Manager` | 201 |
| E6 | `cole` avec un rôle `Accountant` seul | 403 |
| E7 | `cole` possède un second personnage dans une autre corporation qui porte `Director` | 403 (seul un personnage de la corporation du collage compte) |
| E8 | Lecture des rôles de `cole` en échec ESI | 503, composition intacte |
| E9 | Rôles illisibles pour un personnage, `Director` lu sur un autre personnage de la corporation | 201 |
| E10 | Composition sans auteur (`recordedBy` `null`), `aria` colle | 403 ; avec `Director`, 201 |
| E11 | `cole` sans rôle sur une lune sans extraction | 404 (M10), pas 403 |

## Cas limites

- Une lune partagée par deux corporations : la composition et le droit sont par `(corporationId, moonId)` ; le rôle d'une autre corporation n'ouvre rien.
- Rôles en cache : aucun (appel `/characters/{id}/roles/` à chaque remplacement par un non-auteur, rare).

## Hors périmètre

- Historique ou annulation d'un collage ; notification à l'auteur remplacé.
- Rôles `Director` d'un autre module (assets), nouveau scope, nouvelle table.
- Vérification en jeu du débit et du format du scan (points restants de #368).

## Décisions ouvertes (prises en autonomie, à relire)

- « Rôle corporation » = `Director` ou `Station_Manager` : ce sont les rôles qui gèrent les extractions (synchro) et la corporation ; un autre choix (Director seul) se change dans une constante.
- Rôles illisibles = 503 plutôt que refus 403, pour ne pas faire passer un incident ESI pour un défaut de droit.
