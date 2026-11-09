# Lot C — issues créées (2026-10-22)

| id plan | # | titre |
|---|---|---|
| C1 | #179 | Projet : les estimations sont figées à la création |
| C2 | #183 | Profit d'un projet : un coût inconnu compte 0 |
| C3 | #185 | Étapes : les runs saisis sur un intermédiaire sont réécrits par la cascade |
| C4 | #187 | Job rattaché à la main : perdu au matching suivant |
| C5 | #188 | Rattachement manuel : un job annulé est accepté et affiché « terminé » |
| C6 | #190 | Étape de réaction : le ME saisi est appliqué aux matériaux |
| C7 | #191 | Ajout d'étape : runs stockés comme quantité, groupe découpé non recalculé |
| C8 | #193 | Arbre de production : un type absent du SDE fait disparaître l'arbre sans message |
| C9 | #194 | Liste d'achats : structure de marché et coût de transport en dur |
| C10 | #196 | adapt-stock sans effet ; adapt-stock et apply-stock sans appelant |
| C11 | #197 | Syncs à la demande (matching des jobs, compétences) : les échecs sont tus |
| C12 | #199 | Structures : les rigs ne sont pas validés |
| C13 | #200 | API Industry : validation manquante aux bornes (TE, UUID, dates, frais) |
| C14 | #202 | Suppression d'étape : règles appliquées seulement dans l'interface |
| C15 | #203 | Bonus de temps des compétences dupliqué et requêté par étape |
| C16 | #204 | PATCH d'un projet : l'arbre de production est construit pour rien |
| C17 | #205 | Achats : une transaction wallet rattachable à plusieurs projets |
| C18 | #206 | Couche API Industry : conventions API Platform non suivies et aucun test HTTP |
| C19 | #207 | Personnage sans scope des jobs : ses jobs actifs sont marqués « livrés » |
| C20 | #208 | Jobs rattachés aux étapes : statut, coût et date de fin jamais rafraîchis |
| C21 | #209 | Jobs : statuts reverted et paused mal gérés |
| C22 | #210 | Fin de sync des jobs : les compteurs incluent les jobs des autres membres de la corporation |
| C23 | #211 | Changement de corporation : corporationId mis à jour seulement à la connexion |
| C24 | #212 | Index de coût et prix ajustés : cache expiré entre deux syncs, échec sans reprise |
| C25 | #213 | Jobs corporation relus par chaque personnage, sans ETag |
| C26 | #214 | Jobs en cache : coût 0 et produit = blueprint à la création, pas de rafraîchissement |
| C27 | #215 | Temps réel de la sync des jobs : un cycle par personnage, écrans industrie non abonnés |
| C28 | #216 | Notification de job terminé : trous et doublons |
| C29 | #217 | Contrats publics : items perdus sans signal, 0 au lieu d'inconnu, items relus à chaque sync |
| C30 | #218 | Slot Tracker : compétences et jobs inconnus remplacés par des valeurs plausibles |

## Commentaires

- #2 : https://github.com/srekcud/evetools/issues/2#issuecomment-6328251025 (appelants StockpileStartingStockReducer:31,69 et CreateStepProcessor:87,130,186)
- #44 : https://github.com/srekcud/evetools/issues/44#issuecomment-6328251432 (regenerate-steps et rig-options périmés ; apply-stock/adapt-stock sans appelant)
