# Spec #372 — Minage groupé : retirer un scan erroné d'un site

Statut : validée en autonomie (mode nuit). Décision de Sylvain du 2026-11-07 (commentaire de l'issue) : option C, le scan est masqué des estimations à venir sans recalculer les métriques enregistrées. Dépend de #306 (scans partagés, dans main) et de #267 (R15, métriques jamais recalculées).

## Règles métier

- **R1 — Qui peut retirer.** L'auteur du scan, le propriétaire de l'opération du site et les administrateurs de l'opération (`MiningOperationAccess::canAdminister`). Un autre participant reçoit 403. Site privé (sans opération) : l'auteur seul. Auteur supprimé (inconnu) : seuls le propriétaire et les administrateurs de l'opération.
- **R2 — Accès au site inchangé.** Le site se résout comme pour la lecture (`MiningSiteRequest::siteOf`) : non-participant, membre en attente ou ayant quitté, site privé d'un autre : 404 « Mining site not found ». Un auteur qui a quitté l'opération perd donc aussi ce droit.
- **R3 — Masqué, pas effacé.** Le scan reste stocké avec un horodatage de masquage (`hiddenAt`, heure du serveur). Aucune métrique de aucun scan n'est recalculée : ETA, valeur restante et types des scans suivants restent ceux annoncés à l'époque (R15 de #267). Le statut du site ne change pas (un site clos par ce scan reste clos).
- **R4 — Invisible partout.** Un scan masqué n'apparaît plus dans la liste des scans, répond 404 « Mining site scan not found » à la lecture et à une seconde suppression, et ne compte plus dans le `number` (rang chronologique parmi les scans visibles), dans `lastScanAt`, `lastScanNumber`, `lastScanAuthorName`, ni dans le résumé du site (premier scan, durée observée, valeur restante, ETA du site : ceux du dernier scan visible).
- **R5 — Estimation à venir.** Le prochain scan calcule son estimation sans les scans masqués : même résultat que si le scan masqué n'avait jamais été fait. Règle « un seul scan par seconde » : un scan masqué occupe encore sa seconde (même horodatage refusé en 422).
- **R6 — Temps réel.** Après le masquage sur un site d'opération, l'événement `site_scan_hidden` est publié sur le topic Mercure `/user/{userId}/mining-operations` (déjà déclaré dans `MercurePublisherService::getTopicsForUser()`) pour le propriétaire et les membres acceptés (`MiningOperationAudience::participantIdsOf`), charge utile `siteId`, `scanId`. Site privé : rien. Échec de publication : sans effet sur la suppression.
- **R7 — Réponse.** `DELETE /api/mining-sites/{siteId}/scans/{id}` : 204 sans corps. Identifiant mal formé ou inconnu : 404. Anonyme : 401.

## Exemples chiffrés

Opération O, propriétaire Aria, administrateur Brann, membre Cyra, membre Dax ; site S lié à O ; scans 1 (Aria 20:00), 2 (Cyra 20:10), 3 (Cyra 20:20).

| # | Cas | Attendu |
|---|---|---|
| E1 | Cyra retire son scan 2 | 204 ; liste : 2 scans, numéros 1 et 2 (l'ancien 3 devient 2) ; ETA du scan 3 inchangées |
| E2 | Aria (propriétaire) retire le scan 2 de Cyra | 204 |
| E3 | Brann (administrateur) retire le scan 2 de Cyra | 204 |
| E4 | Dax (membre, non auteur) retire le scan 2 | 403, scan toujours visible |
| E5 | Membre en attente ou étranger retire un scan de S | 404 « Mining site not found » |
| E6 | Aria retire le scan 2 de Cyra puis scanne à 20:30 | ETA du 4e scan = celles d'un site qui n'aurait eu que les scans 1, 3 et le nouveau |
| E7 | Cyra retire le scan 3 (le dernier) | site : `lastScanNumber` 2, valeur restante et ETA du scan 2 |
| E8 | Brann retire un scan de S | `site_scan_hidden` reçu par Aria, Brann, Cyra, Dax ; rien pour un membre en attente |
| E9 | Scan d'un site privé retiré par son propriétaire | 204, aucun événement ; autre utilisateur : 404 |

## Cas limites

- Tous les scans masqués : le site se lit comme un site sans scan (champs de dernier scan à null) ; le prochain scan est un premier scan (au moins un type exigé, #267).
- Compte de l'auteur supprimé : le scan reste (auteur inconnu), retirable par le propriétaire ou un administrateur.
- Site clos : un scan reste retirable (nettoyage de l'historique), aucun rouvrir.

## Décisions prises en autonomie

1. Colonne `mining_site_scans.hidden_at` (timestamp NULL), migration additive sans effet sur l'existant.
2. Numérotation recalculée parmi les scans visibles (lecture seulement) ; le `number` publié dans les événements passés reste ce qu'il était.
3. Pas de réouverture automatique d'un site clos par le scan retiré, pas de recalcul du statut.
4. Le propriétaire du site (non administrateur) ne peut pas retirer le scan d'un autre : la décision cite l'auteur, le propriétaire de l'opération et ses administrateurs.

## Hors périmètre

- Front (bouton de retrait dans le fil des scans, #366 et #304) ; restauration d'un scan masqué ; purge physique des scans masqués.
