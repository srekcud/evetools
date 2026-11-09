# File de la boucle de nuit (ordre = priorité ; l'état vient de GitHub et des threads, ne pas le dupliquer ici)

scheduled_task_id: scheduled-task:command:mcp:755b9cb9-06d4-4e9e-b0d2-99ca669da8ef:schedule-task:night-orchestrator-2026-11-03
journal: /home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-03-night.md
parallélisme maximal : 2
repo : srekcud/evetools

Issues (group:mining-ops, hors Industry, hors circuit:decision) :
305 | m | plusieurs sites dans une même opération | PRÉREQUIS : #267 d'abord (entité Site et scans absents du code). Ne pas lancer #305 tant que #267 n'est pas fermée ; #267 attend Sylvain (validation de la spec, exemple réel de collage). Relancer #305 en 1er dès que #267 est dans main.
307 | s | présence des participants pendant l'opération
310 | m | opérations récurrentes
311 | m | vaisseau de boost et compresseurs montés
312 | m | planning du hauler
271 | s | notifications d'opération (ledger prêt, paiements, épuisement)
316 | s | récapitulatif d'opération à partager
317 | m | saison de la corporation
267 | l | estimer l'épuisement d'une belt ou d'une anomalie à partir de plusieurs scans (spec validée par Sylvain le 2026-11-03 : docs/specs/267-*)
309 | m | combien de mineurs pour vider un site (dépend de #267)
304 | l | compagnon détachable à garder à côté de la fenêtre du jeu
306 | l | scans partagés en direct à toute la flotte (dépend de #267)
308 | m | valeur d'un chunk avant de le miner (décision Sylvain : débit 25 000 m³/h en constante, format de scan supposé)
273 | l | lunes d'alliance, taxe lunaire et suivi des paiements (décisions Sylvain dans decisions-2026-11-03.md : scope wallet corpo obligatoire, taxe sur valeur compressée par catégorie, rapprochement par payeur, période réglable)
251 | s | Profit Margins : le revenu de vente Jita ignore la couverture du carnet (group:margin-display, ajoutée le 2026-11-04 à la demande de Sylvain)
250 | m | Quantité de matériaux : le ME appliqué à une réaction (group:core-wiring)
249 | m | Quantité de matériaux : rigs empilés additionnés par l'historique (group:core-wiring)
192 | m | Profit Margins : décrypteur pris en compte de façon incohérente (group:margin-display)
180 | m | Estimation des coûts : troisième définition de la marge, runs présentés comme unités (group:margin-display)
178 | m | Profit Margins : 0 ISK pour une donnée inconnue, « par unité » calculé par run (group:margin-display)
179 | l | Projet : les estimations sont figées à la création (group:core-wiring)
149 | l | Noyau : structure assignée non transmise, choix de structure fait deux fois (group:core-wiring)
368 | m | valeur du chunk dans les vues calendrier et points à vérifier en jeu (suite de #308, dans main) (group:mining-ops, ajoutée le 2026-11-04)
364 | m | vaisseau de chaque personnage d'une opération (group:mining-ops)
366 | l | écran d'estimation d'épuisement d'un site (front de #267, dans main) (group:mining-ops)
367 | m | badge de compression par type de site et bandeau d'avertissement | PRÉREQUIS : #366 fermée ou fusionnée dans main (group:mining-ops)
371 | m | fil des scans partagés et rappel de re-scan (front de #306) | PRÉREQUIS : #306 dans main (group:mining-ops)
369 | l | écrans de la taxe lunaire (front de #273) | PRÉREQUIS : branche fix/night-273 poussée et fusionnée dans main par Sylvain (group:mining-ops)

Exclues : #261 (PR #326), #268 #269 #274 #314 #315 (autre thread, commits déjà dans main : à fermer par Sylvain après vérification), #256 (Industry Scanner), circuit:decision restants (#313 gardée et reportée ; les décisions de Sylvain sont dans decisions-2026-11-03.md), tout code Industry/GroupIndustry/Stockpile SAUF les issues core-wiring et margin-display ci-dessus (autorisées par Sylvain le 2026-11-04), #219 (circuit:decision), group:cleanup #302 #299 (circuit:decision, reportées après big bang par Sylvain), #365 #372 (circuit:decision), Makefile/.docker/CI/déploiement.
