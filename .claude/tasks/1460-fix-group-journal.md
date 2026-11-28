# Journal #1460 (group:pi-supply, un seul issue)

- Décision de Sylvain 2026-11-28 : option (a), détenteur déduit, sans réglage ni migration. Circuit m, mode nuit.
- Preuves (1b) : tableau par launchpad vide = pas de bug d'affichage (confirmé) ; `launchpadRow` ne portait pas le personnage (ajouté).
- Technicité : niveau 2 (nouvelle ressource API exposant des données d'un utilisateur). Revue sécurité sans constat bloquant. Push de `main` laissé à Sylvain (brief sans autorisation), issue laissée ouverte.
- Tokens sous-agents : spec 115k, rouge back 108k + front 95k, vert back 56k + front 92k, revue api-platform 37k + sécurité 51k + test-skeptic 69k, tests complémentaires 88k, correctif 37k.
- Revue : provider avec logique métier (à extraire dans un service `PlanetarySupplyHoldersReader`, non fait : le constructeur du provider est figé par les tests) ; révocation de jeton invisible (spec corrigée) ; test-skeptic : 15 tests ajoutés, un cas réel corrigé (destinataire à lignes toutes inconnues : volume inconnu et non 0).
- Gates : unit 19766 OK, PHPStan OK, deptrac 0, Integration/Functional OK (1 échec flaky, IndustryProjectContributionRepositoryClaimedQuantityTest, ordre d'uuid, hors lot, vert en isolé 3 fois), vitest : échecs préexistants identiques sur origin/main (CommandPalette, blueprintNameCopyButtonWindow, Help/marked absent du volume), build front non exécutable (marked). Infection : 84 % covered MSI, 4 échappés dans le provider dont `> 0` tué ensuite par un test.
- Phase rouge rouverte une fois (tests complémentaires), aucune assertion existante modifiée.
