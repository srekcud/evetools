---
name: adr
description: Rédige une ADR (Architecture Decision Record) à partir d'une décision prise, au format du registre docs/adr/, et met à jour l'index.
disable-model-invocation: true
argument-hint: <décision en une phrase>
---

Décision : $ARGUMENTS

1. Vérifie que la décision a bien été prise par Sylvain (dans la conversation, une issue ou une PR). Une ADR consigne une décision, elle n'en prend pas : si la décision n'est pas actée, arrête-toi et propose plutôt les options.
2. Lis `docs/adr/README.md` (gabarit et index) et les ADR existantes. Si la décision en remplace une, c'est une nouvelle ADR qui remplace l'ancienne : on ne réécrit jamais une ADR acceptée.
3. Délègue la rédaction à l'agent `doc-writer`, avec :
   - le numéro suivant (`NNNN`) et un nom de fichier court ;
   - le contexte (problème, contraintes, ce qui a été mesuré ou constaté, avec `fichier:ligne`) ;
   - la décision ;
   - les conséquences, positives et négatives, et ce qu'il faudra faire ;
   - le statut (`Acceptée`, ou `Remplace ADR-XXXX`) et la date du jour.
4. Vérifie le résultat :
   - l'index `docs/adr/README.md` est à jour ;
   - l'ADR remplacée, s'il y en a une, est passée à `Remplacée par ADR-NNNN` ;
   - aucun nom de joueur ou de corpo, aucune attribution à une IA.
5. Présente l'ADR à Sylvain. Ne commite pas sans son accord.
