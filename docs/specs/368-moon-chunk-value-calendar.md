# Spec #368 — Valeur du chunk dans les vues calendrier (suite de #308)

**Statut : validée en autonomie en mode nuit le 2026-11-04, à relire par Sylvain.**

Lot `group:mining-ops`, circuit `circuit:m`, front seul : aucune API, aucune migration, aucun topic Mercure (l'enregistrement d'une composition reste synchrone, M11 de #308). Maquette retenue : `mockups/grouped-mining-v4.html` (ligne `chunkValueLine` : « ≈ valeur · part / pers. à 6 » ou « valeur inconnue · … »).

## Règles

- **C1 — Ligne de valeur dans la case.** Dans les vues semaine et mois de `MoonChunksTab.vue`, chaque case de chunk dont `chunkValue` n'est pas `null` affiche la même ligne que la vue liste : « ≈ total · part / pers. à 6 » (`status = known`), « valeur inconnue · coller le scan de la lune » (`no_scan`), « valeur inconnue · prix manquant » (`no_price`). Mêmes textes i18n, mêmes attributs `data-cell="value"`, `data-value-status`, `data-unknown-reason`. Une valeur inconnue n'affiche jamais de chiffre ni « 0 ».
- **C2 — Chunk sans estimation.** Un chunk dont `chunkValue` est `null` (explosé, M8 de #308) n'affiche aucune ligne de valeur dans la case ; sa case reste inchangée.
- **C3 — Accès au détail.** La ligne de valeur de la case est un bouton (`data-toggle-detail`, `aria-expanded`) qui ouvre le détail du chunk, identique à celui de la vue liste (`MoonChunkValueDetail`) : formule du volume, total, parts à 4, 6 et 8, tableau minerai / part / unités / valeur, date du scan, bouton « re-coller », zone de collage immédiate sans composition. Le détail s'affiche sous la grille du calendrier de la corporation, sur toute la largeur (`data-detail="<id>"`). Un second clic referme.
- **C4 — Collage depuis le calendrier.** Un collage réussi depuis ce détail recharge les extractions (comme en liste) ; un refus 422 s'affiche ligne par ligne sans recharger.
- **C5 — État partagé.** Le détail ouvert est le même état que celui de la liste : ouvrir un chunk en liste puis passer en calendrier le montre ouvert (`aria-expanded` vrai), et inversement.
- **C6 — HUD.** Un état inconnu n'est jamais signalé par la couleur seule (glyphe ⚠, comme en liste). Le texte long d'un nom de lune ou d'une ligne de valeur passe à la ligne (`break-words`), sans déborder de la case.

## Exemples

Date simulée : jeudi 2026-11-05 19:00 UTC, harnais `moonChunksHarness.ts`.

| # | Cas | Attendu |
|---|---|---|
| E1 | Semaine, chunk `VALUED_CHUNK` (jeudi 20:00, 40 700 000 ISK, 6 783 333 à 6) | case jeudi : ligne `known` contenant 40 700 000 et 6 783 333 |
| E2 | Mois, même chunk | même ligne dans la case du 2026-11-05 |
| E3 | Semaine, `NO_SCAN_CHUNK` puis `NO_PRICE_CHUNK` | `unknown`, raisons `no_scan` / `no_price`, textes différents, aucun chiffre |
| E4 | Mois, chunk explosé du mardi 2026-11-03 (`chunkValue: null`) | aucune ligne de valeur dans sa case |
| E5 | Semaine, clic sur la ligne de `VALUED_CHUNK` | détail : total, parts 4/6/8, tableau, date du scan ; second clic referme |
| E6 | Semaine, `NO_SCAN_CHUNK`, détail, collage de `Bitumens\t40 %\nChromite\t0,6` | POST `/mining/moon-compositions` pour la corporation et la lune, puis second GET des extractions |
| E7 | Détail ouvert en liste, puis vue semaine | la ligne de la case est `aria-expanded="true"` et le détail est affiché |
| E8 | Semaine, `LONG_MOON_CHUNK` | nom complet dans le détail, ligne de valeur `unknown` |

## Cas limites

- Deux chunks le même jour : chacun a sa ligne et son détail (clés par id d'extraction).
- Détail d'un chunk hors de la période affichée : non montré (le calendrier ne rend que ses cases), l'état reste mémorisé.
- Accès `denied` ou `never_synced` : comportement inchangé.

## Hors périmètre

- Points « à vérifier en jeu » de l'issue (débit 25 000 m³/h, format du scan) : vérification humaine, aucun code ; l'issue reste ouverte pour eux.
- « À décider » (restreindre le remplacement d'une composition à l'auteur ou à un rôle) : décision produit, non prise ici.
- Valeur après booster, taxe, ledger réel (#273) ; création d'une opération depuis un chunk.

## Décisions ouvertes (prises en autonomie, à relire)

- Le détail s'ouvre sous la grille et non dans la case : une case de grille de 7 colonnes est trop étroite pour le tableau des minerais.
- Un chunk explosé n'a pas de ligne de valeur en calendrier (cohérent avec le « — » de la liste).
