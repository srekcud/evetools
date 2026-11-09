# Décisions de Sylvain sur les issues circuit:decision (2026-11-03, session interactive)

| Issue | Décision | Suite |
|---|---|---|
| #362 fusion solo/groupe | Big bang sans compatibilité de routes (les liens /join/:code et favoris existants cassent) | boucle Industry |
| #363 apports au prix réel | Toujours le prix marché, pas de prix payé saisi | boucle Industry |
| #300 barèmes de location | Brancher (facteur par jour + montant par run, pré-rempli, modifiable, surcharge projet d'abord) | boucle Industry |
| #334 ventes par contrat | Version minimale : contrats publics de la région de la capitale seulement [À CONFIRMER : quelle région exactement, historique 90 j et enchères ignorées par défaut] | boucle Industry |
| #302 commandes console | Tout garder, reporter | après big bang |
| #299 champs jamais lus | Reporter | après big bang |
| #248 PostgreSQL 18 | Après le big bang | après big bang |
| #251 revenu Jita partiel | Signaler « prix partiel » comme #147 côté achat | boucle Industry (ProfitMarginService) |
| #270 temps pondéré | Prorata seulement : fermer l'issue. Vérifié : « Chacun pour soi » existe (MiningSplitMode::EachForThemselves, choix à la création, Clôture → Terminée sans parts ni paiements) | fermer #270 |
| #267 épuisement d'une belt | Spec validée (docs/specs/267-*), passe en circuit:l ; débloque #309 et #306 | file de nuit (avant #309) |
| #313 suggestion de répartition | « On la garde » : reste ouverte, reportée (pas fermée) | après big bang |
| #308 valeur d'un chunk | Implémenter avec 25 000 m³/h comme constante facile à modifier et le format de scan supposé | file de nuit |
| #273 lunes d'alliance | Version complète : scope esi-wallet.read_corporation_wallets.v1 AJOUTÉ aux scopes obligatoires (reconnexion de tous les utilisateurs au déploiement) ; taxe sur la valeur compressée, taux par catégorie de minerai ; rapprochement par personnage payeur ; période réglable par la raffinerie | file de nuit (circuit:l) |
| #276 bonus de copie | Modéliser les rigs de science | boucle Industry (IndustryCalculationService) |
| #219 SCC sur la copie | Garder l'hypothèse actuelle (SCC sur toutes les activités), vérifier en jeu plus tard | rien |

Actions pour Sylvain liées : ajouter le scope wallet corpo force une reconnexion de tous (annoncer au déploiement) ; fermer #270 ; relever en jeu la SCC de la copie (#219), le débit d'extraction d'une raffinerie (#308) et l'ESI flotte (#270 abandonnée).
