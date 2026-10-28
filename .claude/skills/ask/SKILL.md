---
name: ask
description: Mode interactif des questions. Pose les questions à Sylvain avec l'outil AskUserQuestion (choix guidés, recommandation en premier, par petits groupes) au lieu de questions en prose. Utilisée par /spec et par toute skill qui doit trancher avec Sylvain.
argument-hint: <sujet ou liste de questions>
---

Sujet : $ARGUMENTS

Objectif : obtenir des décisions de Sylvain sans lui faire écrire de longues réponses. Chaque question est un choix à cliquer ; il peut toujours répondre en texte libre (« Other »).

## Avant de poser une question

1. Vérifie dans le code, les docs, les ADR et l'issue tout ce qui est vérifiable. Une question dont la réponse est dans un fichier est une question de trop.
2. Ne garde que les points qui sont une décision de Sylvain : règle métier, périmètre, cas limite non couvert, nom de glossaire, arbitrage entre deux options.
3. Ordonne-les : d'abord ce qui conditionne les suivantes.

## Poser les questions

Utilise l'outil `AskUserQuestion`, jamais une liste de questions en prose.

- **Groupe** : 1 à 4 questions par appel, du même thème. Les questions suivantes s'adaptent aux réponses : ne prépare pas tout d'avance.
- **Question** : une phrase complète qui se termine par `?`, avec le contexte minimal pour répondre sans relire l'issue.
- **`header`** : 12 caractères au plus (« Plafond », « Cas limite », « Terme »).
- **Options** : 2 à 4, exclusives, sans option « Other » (ajoutée par l'outil). La recommandation est la première, son label se termine par `(Recommandé)`. Chaque `description` dit la conséquence concrète (ce que le code ou l'écran fera), pas une paraphrase du label.
- **Exemples chiffrés** : mets l'entrée et la sortie attendue dans la `description` ou dans `preview` (comparaison de deux comportements, extrait de tableau, maquette). Les chiffres viennent d'une source lue ; sinon `[TO CONFIRM: …]`, jamais une valeur plausible.
- **`multiSelect: true`** seulement quand les choix ne s'excluent pas (cas limites à couvrir, écrans concernés).
- Décision structurelle (dépendance lourde, séparation ou fusion de service, coût récurrent, couplage difficile à défaire) : au plus deux options, une recommandation, et la question passe avant tout code ou lancement d'agent.

## Après chaque réponse

1. Récapitule en 1 à 3 lignes ce qui est tranché.
2. Une réponse libre est prise au pied de la lettre : ne repose pas la question, reformule seulement si elle ouvre un nouveau point.
3. Enchaîne avec le groupe suivant, adapté. Arrête dès que plus rien ne reste à décider.

## Fin

Rends la liste des décisions, une ligne chacune, avec leur destination (spec, glossaire, journal, issue). Ne crée rien à la place de la skill appelante.

## Quand ne pas poser de question

- **Mode nuit ou session non interactive** (consigne de Sylvain, brief du thread, `AskUserQuestion` indisponible) : n'appelle pas l'outil. Applique les décisions déjà prises, retiens la recommandation pour ce qui est couvert par le brief ou l'issue, et écris le reste dans « Blocages » du journal avec options et recommandation, puis passe à la suite.
- Une décision déjà prise (commentaire d'issue, spec validée, mémoire) ne se redemande pas.
