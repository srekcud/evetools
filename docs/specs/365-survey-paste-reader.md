# Spec #365 — Minage : lire le collage du Mining Survey Chipset en scan de site

Statut : validée en autonomie (mode nuit). Format fixé par les trois commentaires de l'issue (exemple réel en client français, groupes repliés, vérifiés en jeu). Complète la partie « Lecture du collage » de la spec #267.

## Contexte

`POST /api/mining-sites/{siteId}/scans` (#267) reçoit une liste `{typeId, units}`. Aujourd'hui le formulaire de scan (`MiningSiteScanForm.vue`) se remplit ligne par ligne. Cette issue ajoute la lecture du texte copié depuis la fenêtre de résultats du chipset.

## Décision d'architecture

La lecture est une **fonction pure côté front** (`frontend/src/views/MiningSite/miningSurveyPaste.ts`) : le texte ne quitte jamais le navigateur, ce qui satisfait R14 de #267 (jamais enregistré ni loggé) sans nouvelle route. La table `{typeId, typeName, volume}` vient de `useMiningSiteOreTypes` (lignes de `GET /mining/ore-values`, issues du SDE). Aucune modification du back, aucune migration.

## Entrée et sortie

`parseSurveyPaste(text, oreTypes) → { lines: {typeId, units}[], issues: SurveyPasteIssue[] }`

`oreTypes` : liste de `{typeId, typeName, volume: number | null}`. Un `SurveyPasteIssue` porte `kind`, `line` (numéro 1-based dans le texte collé, lignes vides comprises) et `name` quand il en existe un.

## Règles

- **P1 — Lignes de minerai.** Une ligne de scan a au moins trois colonnes séparées par une tabulation (ou, à défaut, au moins deux espaces) : nom, quantité, volume (`<nombre> m3` ou `m³`). Valeur et distance, si présentes, sont ignorées. Valeur `-` (minerai sans prix) : jamais lue comme 0, jamais bloquante.
- **P2 — Quantité.** En unités, entier strictement positif. Séparateur de milliers `.`, `,`, espace, espace insécable ou fine insécable (client français ou anglais) : `31.026` et `31,026` valent 31 026. Toute autre forme (décimale, lettre, zéro, groupes de chiffres mal formés comme `31,02`) rend la ligne illisible (`unreadable`).
- **P3 — Nom.** Comparaison au `typeName` du SDE sans tenir compte de la casse ni des espaces autour. Le nom du collage est anglais même avec un client français. Un nom absent de `oreTypes` donne `unknown_ore` avec le nom ; la ligne n'est pas lue.
- **P4 — Cohérence avec le volume.** Si le volume SDE du type est connu et que la colonne volume est lisible, `unités × volume SDE` doit égaler la colonne volume à `max(1 m³, 1 %)` près, quel que soit le format des nombres de la colonne (français ou anglais, une des deux lectures doit coïncider). Sinon `volume_mismatch` : la ligne n'est pas lue. Volume SDE inconnu : pas de contrôle.
- **P5 — Agrégation.** Les unités des lignes d'un même `typeId` sont sommées (11 lignes d'Ueganite = une ligne). L'ordre de sortie est celui de la première apparition.
- **P6 — Groupe replié.** Une ligne `nom [n] valeur ISK / m³` (séparateur tabulation ou espaces, valeur éventuellement `-`) est un groupe replié : issue `collapsed_group` nommant le minerai, jamais ignorée ni lue comme un scan. Raison : un minerai absent du scan serait compté épuisé par #267.
- **P7 — Lignes indépendantes.** Une ligne en erreur n'empêche pas la lecture des autres. Lignes vides ignorées (sans issue). Texte vide : `lines` et `issues` vides.
- **P8 — Pas d'effet de bord.** Aucun appel réseau, aucun log, aucun stockage du texte.

## Formulaire de scan

- **F1.** Un champ « Coller le résultat du Survey » (textarea) et un bouton « Lire le collage » au-dessus des lignes manuelles de `MiningSiteScanForm.vue`. Le texte est lu au clic, puis le champ est vidé.
- **F2.** Sans `collapsed_group` : les lignes lues remplacent les lignes saisies (type et unités pré-remplis, modifiables avant l'envoi) ; les autres issues sont listées sous le champ (`data-testid="survey-issues"`), une ligne par issue, avec le numéro de ligne et le nom.
- **F3.** Avec au moins un `collapsed_group` : aucune ligne n'est appliquée, les issues sont listées dont le message « {nom} est replié : déplie tous les groupes avant de copier ».
- **F4.** Rien n'est envoyé au serveur par la lecture ; l'envoi reste celui du formulaire (bouton « Enregistrer le scan »), qui garde toutes ses validations. Les lignes lues pré-remplissent seulement le brouillon.
- **F5.** Textes en fr et en (`miningSite.scan.paste.*`). Style HUD déjà en place (carte `eve-card`, mono pour les compteurs).
- **F6.** Texte vide au clic : message `noLines` existant, aucune issue.

## Exemples

Table : Arkonor 22 (16 m³), Ducinium 74533 (16 m³), Ueganite 82205 (5 m³), Veldspar 1230 (0,1 m³) ; groupes non listés ci-dessus ont des variantes `II-Grade` à `IV-Grade` du SDE réel (tests : trois types suffisent).

| # | Collage | Attendu |
|---|---|---|
| X1 | `Arkonor⇥31.026⇥496.416 m3⇥81.500.000,00 ISK⇥6.952 m` | `[{22, 31026}]`, aucune issue |
| X2 | trois lignes Arkonor 31.026 / 33.641 / 35.333 | une ligne `{22, 100000}` |
| X3 | `Ueganite⇥103,856⇥519,280 m3⇥33,100,000.00 ISK⇥14 km` (format anglais) | `[{82205, 103856}]` |
| X4 | `Ducinium II-Grade⇥9.789⇥156.624 m3⇥-⇥9.386 m` | lu, valeur `-` ignorée |
| X5 | `Unobtainium⇥10.000⇥50.000 m3⇥-⇥3 km` | issue `unknown_ore` (ligne 1, « Unobtainium »), `lines` vide |
| X6 | ligne 2 `Arkonor⇥abc⇥1 m3⇥-⇥1 km` entre deux lignes valides | `lines` des deux valides, issue `unreadable` ligne 2 |
| X7 | `Ueganite [11] 112 ISK / m³` | issue `collapsed_group` (« Ueganite »), jamais lue comme scan |
| X8 | `Arkonor⇥31.026⇥400.000 m3⇥-⇥1 km` | issue `volume_mismatch`, ligne non lue |
| X9 | lignes vides, `\r\n` en fin de ligne | pas d'issue, numéros de ligne d'origine |
| X10 | `Arkonor⇥31,02⇥…` ou `0` | `unreadable` |
| X11 | nom `ARKONOR` en majuscules | lu comme Arkonor |

Calculs : X1 `31 026 × 16 = 496 416`. X2 `31 026 + 33 641 + 35 333 = 100 000`. X3 `103 856 × 5 = 519 280`.

## Cas limites

- Collage mélangeant lignes dépliées et un groupe replié : F3 (rien appliqué).
- Même minerai en lignes dépliées et en en-tête replié : F3 également (la ligne dépliée seule ferait sous-compter).
- Plus de 100 types distincts : la lecture les rend tous, le formulaire garde son plafond de 100 lignes et l'API sa validation.

## Hors périmètre

- Pré-remplissage du système ou du nom du site depuis le texte (R13 : absents du collage).
- Lecture de la distance, de la valeur ou du prix (inutilisés par le scan).
- Lecture en un geste (événement `paste` direct) et envoi immédiat sans relecture du brouillon.
- Route serveur de lecture : non voulue (R14).

## Décisions prises en autonomie

1. Parseur côté front, pas de route : le texte ne quitte pas le navigateur.
2. Issues non bloquantes (`unreadable`, `unknown_ore`, `volume_mismatch`) : les lignes lues pré-remplissent le brouillon, l'utilisateur voit les erreurs ; un minerai inconnu du SDE ne peut pas s'ajouter à la main non plus. `collapsed_group` seul bloque l'application (consigne de l'issue).
3. Tolérance du contrôle de volume `max(1 m³, 1 %)`, constante nommée.

Aucun réglage ni écran retiré.
