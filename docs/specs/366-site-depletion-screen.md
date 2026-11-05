# Spec #366 — Minage : écran d'estimation d'épuisement d'un site

Statut : validée en autonomie (mode nuit). Front de #267 : règles de calcul dans `docs/specs/267-site-depletion-estimate.md` (R1 à R19), maquette `mockups/grouped-mining-v4.html` (étapes « Site libre · 1 scan », « Site libre · 3 scans », historique des sites), API livrée : `/api/mining-sites`, `/api/mining-sites/{id}/scans`, `/api/mining-sites/{id}/close`.

Le front **affiche** les valeurs enregistrées par scan (R15) : il ne recalcule ni débit, ni ETA, ni valeur. Seuls sont dérivés côté client : les paliers de couleur, l'anneau, la mise en avant du minerai le plus rentable (R10), la droite et le segment de la courbe (dessinés depuis `trendRate`/`trendEta` et `recentRate`/`recentEta` stockés).

## Emplacement

- **F1** Onglet `sites` (« Sites ») dans `MiningOperationList` (`useTabQuery`, après `season`) : historique R18 et bouton « Estimer un site ». Route `/mining-sites/:id` (nom `mining-site-detail`) : page d'un site. Pas de nouvelle entrée de navigation.
- **F2** Store Pinia `miningSite` : liste, site, scans, création, enregistrement d'un scan, clôture ; erreur du serveur (422, 400, 404) affichée telle quelle, jamais avalée.
- **F3** Aucune action asynchrone serveur (POST synchrones) : pas de topic Mercure. Le fil partagé en direct d'un site lié à une opération est #371.

## Création et saisie d'un scan

- **F4** « Estimer un site » ouvre une fenêtre : libellé obligatoire (100 caractères au plus, noms longs tronqués avec `title`), système solaire facultatif choisi par recherche `/api/sde/solar-systems?q=` (réutiliser la recherche de `MiningStartModal`, l'extraire en composant si ses tests restent verts). Succès : navigation vers la page du site.
- **F5** Saisie structurée (en attendant le parseur #365) : lignes « type de minerai + unités ». Types proposés = lignes de `GET /api/mining/ore-values` (`typeId`, `typeName`, `kind`, `volume`) ; unités entier > 0 ; un type une seule fois ; 100 lignes au plus. Premier scan : au moins une ligne. Ensuite, liste vide autorisée (« Tout épuisé ») : elle épuise tous les types. Rappel affiché sous le formulaire : scanner depuis la même position avec la même portée, sinon un astéroïde hors de portée passe pour épuisé (R6). Le texte saisi n'est jamais conservé après envoi.
- **F6** Après un scan : message « Scan N ajouté · ETA tendance A → B » (A et B : ETA du site avant et après, « inconnue » ou « épuisé » le cas échéant). Le formulaire est absent sur un site `closed`.
- **F7** Nom d'un type absent de la liste des minerais : `common.unknownTypeName` avec le `typeId`, jamais vide.

## Page d'un site

- **F8** En-tête : libellé, système (`solarSystemName`), badge Actif ou Clôturé, nombre de scans, premier scan. Bouton « Clôturer le site » (confirmation, `POST /close`, actif seulement).
- **F9** ETA du site : ETA de tendance en grand, ETA récente à côté, compte à rebours mis à jour chaque seconde (intervalle libéré au démontage), type qui tombe en dernier (le type dont `trendEta` égale celle du site). ETA absente : « inconnue », jamais « jamais » ni une date vide.
- **F10** Paliers (constantes nommées) sur l'ETA de tendance restante : `normal` ; `orange` sous 15 min ; `red` sous 5 min ; `reached` ETA dépassée ; `depleted` dernier scan sans aucun type présent ; `unknown` ETA absente. Un site clôturé à la main n'a ni palier ni compte à rebours (ETA du dernier scan affichée) ; un site clôturé par le serveur parce que tous ses types sont épuisés (R17) reste `depleted`. Le palier se lit aussi par un libellé (pas la couleur seule).
- **F11** Un seul scan : « ETA inconnue : ajoutez un 2e scan du même site ». Restant (unités, m³ si connus, valeur) affiché quand même.
- **F12** Anneau du volume restant sur le volume au premier scan de chaque type, sur les types dont l'ETA n'est pas `not_depleting` : somme des `volumeM3` du dernier scan ÷ somme des `volumeM3` de la première apparition de chaque type. Un `volumeM3` null parmi ces types : anneau inconnu (« – »), jamais 0 %.
- **F13** Signal « débit irrégulier » sur un type dont `maxDeviationPercent` dépasse 10 % (R4) : le débit récent est alors annoncé comme plus fiable ; les deux ETA restent affichées.
- **F14** Rappel de re-scan : site actif dont le dernier scan a plus de N minutes (N réglable de 3 à 30, 10 par défaut, mémorisé dans `localStorage`, clé `miningSite.rescanMinutes`) : pastille « re-scanner » avec l'auteur du dernier scan (`lastScanAuthorName`, omis s'il est null) et rappel « même position, même portée ».
- **F15** Tableau par type (dernier scan) : type, restant (u), débit récent, ETA récente, débit de tendance, ETA de tendance, valeur. Badges : « non miné » (`etaUnknownReason` = `not_depleting`, ligne grisée, n'entre pas dans l'ETA du site), « un seul scan » (`single_scan`), « Épuisé · entre HH:MM et HH:MM » (`depletedBetween`), « irrégulier ». Débits à l'unité par minute, ETA à la minute (heure EVE/UTC, `formatEveClock`).
- **F16** Minerai le plus rentable (R10) : parmi les types non épuisés dont `unitPrice` et `volumeM3` sont connus (unités > 0), celui de plus haute valeur au m³ (`unitPrice × units ÷ volumeM3`) est mis en avant avec ses deux ETA ; égalité : le premier du tableau ; aucun type éligible : pas de mise en avant, pas de message d'erreur.
- **F17** Valeur restante : `remainingValue` formatée ; null → « inconnue » et liste des types sans prix (`missingPriceTypeIds` nommés), jamais 0. Types épuisés comptés 0 seulement quand l'API le dit.

## Courbe et évolution des ETA (R16)

- **F18** Courbe SVG : un point par scan et par type présent (grise pour `not_depleting`), droite de tendance du dernier scan prolongée jusqu'à 0 (`Q(t) = trendRate × (trendEta − t)`), segment du débit récent en pointillés jusqu'à `recentEta`, cercle creux à l'épuisement. Axe de temps borné à 300 min après le premier scan. Bascule unités / m³ ; m³ désactivé tant qu'un volume affiché est null. Légende nommée et alternative texte (`role="img"` + `aria-label`).
- **F19** Sous la courbe : évolution des ETA annoncées à chaque scan (`recentEta`, `trendEta` de chaque scan, tel qu'enregistré), du plus récent au plus ancien : numéro, heure, ETA de tendance, ETA récente, « inconnue » le cas échéant.

## Historique des sites (R18)

- **F20** Tableau de l'onglet Sites : libellé, système, premier et dernier scan, durée observée (`observedSeconds`), volume au départ (`firstVolumeM3`, m³), valeur au départ (`firstValue`, inconnue ≠ 0), débit de tendance moyen (`averageTrendRate`, u/min), statut ; ligne cliquable vers la page. Du plus récent au plus ancien (ordre de l'API). Site sans scan : colonnes de scan « – ». Filtre par système : liste des systèmes présents dans les sites chargés, filtrage côté client. Action « Clôturer » sur un site actif (confirmation).
- **F21** Liste vide : message avec le bouton « Estimer un site ». Erreur de chargement : bandeau d'erreur (`role="alert"`).

## Back (une ligne, pour F8 et F20)

- **B1** `MiningSiteResource` expose `solarSystemName` (`?string`, nom du `MapSolarSystem`, null sans système). Aucun autre changement d'API, aucune migration.

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| X1 | ETA de tendance dans 20 min / 10 min / 4 min / passée | `normal` / `orange` / `red` / `reached` |
| X2 | Dernier scan : tous les types `depleted` | `depleted`, anneau 0 %, pas de compte à rebours |
| X3 | Veldspar 15 ISK, 0,1 m³ (150 ISK/m³) ; Scordite 25 ISK, 0,15 m³ (166,7 ISK/m³) | Scordite mis en avant |
| X4 | Veldspar sans `unitPrice` ; Scordite avec | Scordite mis en avant ; valeur du site inconnue, « sans prix : Veldspar » |
| X5 | Anneau : Veldspar 30 000 → 18 000 u à 0,1 m³ ; Scordite non miné (5 000 stable) | (18 000 × 0,1) ÷ (30 000 × 0,1) = 60 % |
| X6 | `maxDeviationPercent` 12,22 / 2,22 | irrégulier / non |
| X7 | Dernier scan il y a 11 min, délai 10 | « re-scanner » ; à 9 min : absent |
| X8 | 1er scan seul | « ajoutez un 2e scan », ETA « inconnue » |

## Cas limites

- Noms de minerai, de système et libellés très longs : tronqués, `title` complet, pas de débordement à 320 px.
- Site clôturé à la main avec des types restants : ETA du dernier scan, sans palier ni formulaire.
- 404 sur un site d'autrui ou inexistant : message d'erreur de la page, pas de page vide.
- FR et EN : clés sous `miningSite.*` dans les deux fichiers ; direction design HUD EVE (`eve-card`, police mono pour les chiffres, accents cyan, ambre pour « inconnu »).

## Hors périmètre

Parseur du collage et Ctrl+V (#365) ; lecture du presse-papiers et compagnon (#304) ; fil partagé, rappel entre participants, scans en direct (#306, #371) ; rattacher un site à une opération et plusieurs sites par opération (#305) ; badge de compression (#367) ; suppression d'un scan (#372) ; statistiques entre sites (#309).
