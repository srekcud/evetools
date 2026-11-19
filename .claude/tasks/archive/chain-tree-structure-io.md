# Arbre des jobs (onglet Chaîne) : structure conseillée et colonne Entrée → sortie

Retour de Sylvain sur `frontend/src/components/group-industry/ProductionChainJobTree.vue` (vue arbre de `ProductionChain.vue`).

## 1. Le nom de la structure conseillée dépasse de la case

Colonne `structure` : `row.node.plannedStructure.name` (et `row.node.structures`) sont des `span.block.truncate.max-w-48` dans une cellule de tableau en layout auto. Un nom long (ex. `1DQ1-A - 1-st Thetastar of Dickbutt Engineering Complex`) sort de la cellule.

Attendu : la cellule structure a une largeur fixe (wrapper `div` avec une largeur explicite, par ex. `w-48`), chaque nom reste sur une ligne tronquée avec `…` à l'intérieur de cette largeur, le nom complet dans `title`. Rien ne déborde de la cellule. Les textes secondaires (« Conseillée », avertissement hors système préféré, « Structure inconnue… ») restent dans la même largeur et passent à la ligne.

## 2. La cause « (#319) » répétée sur toutes les lignes

Colonne `inOut` : chaque ligne de job affiche `UnknownContributionValue` + le texte `groupIndustry.chain.ioUnknownCause` (« lieux des jobs non synchronisés (#319) »). Répété sur toute la colonne, c'est du bruit, et un numéro d'issue ne parle pas aux joueurs.

Attendu :
- chaque ligne de job garde `UnknownContributionValue` (`data-testid="chain-io-unknown"`), sans texte de cause dans la cellule ;
- la cause est affichée une seule fois, dans le pied de la vue arbre (le `footer` de légende de `ProductionChain.vue`, ou une note sous le tableau), par ex. `data-testid="chain-io-unknown-cause"` : « Entrée → sortie : lieux des jobs pas encore synchronisés » ;
- les libellés FR et EN ne contiennent plus « #319 » (clé `groupIndustry.chain.ioUnknownCause` dans `frontend/src/i18n/locales/{fr,en}.json`) ;
- la note n'apparaît qu'en vue arbre (pas en vue flux).

Spec d'origine : `docs/specs/337-production-chain.md` R10 (« affiche la cause ») ; la cause reste affichée, une fois au lieu de par ligne. Mettre à jour R10 en conséquence (sans le numéro d'issue dans le texte affiché).

## Tests

`frontend/tests/components/group-industry/productionChain.spec.ts`, test « always shows the input and output locations as unknown with their cause, never a location » (l. ~256) : à adapter (cellule = inconnu sans cause ; cause présente une seule fois ; absente en vue flux ; aucun libellé ne contient `#319`).
Le débordement est une affaire de CSS (jsdom ne calcule pas la mise en page) : un test qui vérifie la présence du wrapper à largeur fixe et de `truncate` + `title` sur le nom suffit.

Commandes (depuis le worktree) : `npm --prefix frontend run test -- productionChain`, puis `npm --prefix frontend run lint` et `npm --prefix frontend run build` (restaurer ensuite `frontend/dist` et `frontend/tsconfig.tsbuildinfo` s'ils changent).
