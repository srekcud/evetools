---
name: spec
description: Transforme une issue en spec validée (docs/specs/<n>-<slug>.md) par une série de questions courtes, avant toute phase TDD.
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

1. Lis l'issue (`gh issue view $ARGUMENTS --json title,body,comments` : sans `--json`, `gh` échoue sur l'API Projects classic), `docs/glossary.md`, les ADR pertinentes et le code concerné. Pour chaque service existant que la spec modifie ou réutilise, lis ses tests de caractérisation et son harnais (`grep -rln "<Classe>\|Currently.*Issue" tests`). Vérifie tout ce qui est vérifiable avant de poser une question.
1b. **Preuves de l'issue** (audits périmés constatés en #149, #178, #180, #192) : vérifie chaque preuve citée (`fichier::méthode`, valeur, comportement) dans le code actuel, et lance `git log -S '<symbole>' --oneline -- <fichier>` pour retrouver un correctif déjà passé. Une preuve fausse va dans le journal et son point sort de la spec ; si aucune ne tient, propose la fermeture de l'issue au lieu d'une spec.
2. **Tranche les questions** avec la skill `ask` (en mode nuit, elle retient la recommandation sans poser de question ; en mode jour, outil `AskUserQuestion` choix guidés avec recommandation en premier, par petits groupes de questions (2 ou 3 à la fois), en adaptant les suivantes aux réponses. Couvre :
   - le comportement attendu, avec des exemples chiffrés (entrée → sortie attendue) ;
   - les cas limites (produit à plusieurs unités par run, arrondis, données ESI manquantes, réactions…) ;
   - ce qui est explicitement hors périmètre ;
   - l'accès : pour chaque identifiant reçu d'un autre périmètre (`structureId`, `corporationId`, id d'opération) et chaque donnée de corporation, le contrôle d'appartenance exact et la fraîcheur de sa source (ex. `Character.corporationId` ne se rafraîchit qu'à la connexion). Un défaut d'accès préexistant que la spec reprend est écrit comme tel, avec l'issue ou la décision qui le traite ;
   - les termes du glossaire : s'il en manque un, propose une définition.
3. Rédige `docs/specs/$ARGUMENTS-<slug>.md` :
   - **120 lignes au plus** : tout ce qui dépasse (clés i18n, détail des endpoints, décisions mineures) est laissé à l'implémentation ;
   - contexte (anonymisé) ;
   - règles métier ;
   - exemples chiffrés sous forme de tableau (ce seront les tests rouges) ;
   - cas limites ;
   - accès et fraîcheur (contrôles, source, ex-membre, structure non suivie) ;
   - tests existants contredits ou modifiés par la spec (fichier, raison) ;
   - hors périmètre ;
   - termes du glossaire ajoutés ou modifiés.
4. Mets à jour `docs/glossary.md` si des termes ont été validés.
5. 🚦 **Mode nuit, par défaut** : la spec est validée en autonomie. Les questions ouvertes sont tranchées sur la recommandation et notées dans « Décisions » de la spec et du journal, puis `/tdd-red` démarre. **Mode jour**, seulement si Sylvain le demande (consigne ou brief du thread) : présente la spec, elle n'est validée qu'avec son accord explicite.

N'écris ni test ni code.
