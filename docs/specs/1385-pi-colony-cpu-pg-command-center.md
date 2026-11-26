# Spec #1385 : CPU et PG libres par colonie, niveau du command center contre le skill

Mode nuit : validée en autonomie, décisions notées en bas. Empilée sur #1384 (PR #1419) : même écran Colonies, même ligne de `SKILLS_WRITTEN_AT_ZERO`. Maquette locale non versionnée : `pi-production-mockup-2026-11-25.html` (jauges CPU et PG de la ligne, bloc « Command center »).

## Contexte

Une colonie PI est bornée par la CPU et le PG que fournit son command center. L'écran ne dit ni ce qui reste, ni si le command center pourrait être monté avec le skill Command Center Upgrades (type 2505) du personnage.

## Données vérifiées (SDE importée, base de dev, 2026-11-26)

- Command centers (groupe 1027) : le niveau 0 est le type de base du pin (`Barren Command Center`, 2524 : CPU 1675, PG 6000). Les niveaux 1 à 5 sont les types `Limited`, `Standard`, `Improved`, `Advanced`, `Elite` + le nom du type de base (`Elite Barren Command Center`, 2133 : 25415 CPU, 19000 PG). Sortie : attributs `cpuOutput` (48) et `powerOutput` (11).
- Coût d'un pin : attributs `cpuLoad` (49) et `powerLoad` (15) de son type (ex. launchpad 3600 CPU, 700 PG). Extracteur (ECU, groupe 1063) : coût de base, plus par tête `ecuExtractorHeadCPU` (1690, 110) et `ecuExtractorHeadPower` (1691, 550), fois `extractorNumHeads`.
- Les attributs 30 et 50 (`power`, `cpu`) n'existent pas sur ces types : ne pas les lire.
- Aucun lien n'est stocké avec sa longueur ni son niveau : leur coût n'est pas calculable (la maquette le dit aussi).

## Backend

1. Service `PlanetaryPowerCalculator` (`src/Service/Planetary/`), lecture de l'SDE par `DgmTypeAttributeRepository` et `InvTypeRepository`, constantes nommées (identifiants d'attributs, groupe 1027, préfixes de niveau), mémo des lectures SDE remis à zéro (`ResetInterface`, modèle `DogmaAttributeValues`).
2. Sortie du command center d'une colonie : pin dont le type est du groupe 1027. Niveau 0 : son propre type. Niveau `n` de 1 à 5 : le type nommé `<préfixe n> <nom du type du pin>`. Type introuvable ou attribut absent : sortie inconnue (`null`), jamais une valeur par défaut. Une colonie sans pin command center : inconnue.
3. Charge : somme de `cpuLoad` et `powerLoad` des pins autres que le command center ; pour un ECU, plus `têtes × (1690, 1691)`. Un pin dont le type n'a pas ces attributs, ou un ECU sans nombre de têtes, rend la charge inconnue (`null`). Les liens ne sont pas comptés.
4. `ColonyResource` (liste et détail, sans migration) gagne : `cpuUsed`, `cpuMax`, `powergridUsed`, `powergridMax` (`?int`, tous `null` quand la sortie ou la charge est inconnue), `commandCenterSkillLevel` (`?int`, niveau 2505 du personnage de la colonie, `null` sans ligne de skill) et `commandCenterUpgradable` (`?bool` : `skill > upgradeLevel`, `null` si le skill est inconnu). `upgradeLevel` existe déjà.
5. Sync des skills : `CachedCharacterSkill::SKILL_COMMAND_CENTER_UPGRADES = 2505` ajouté à `SKILLS_WRITTEN_AT_ZERO` pour qu'un 0 soit écrit et distinct de « pas synchronisé » (même mécanisme que 2495 de #1384).
6. Portée : colonies de l'utilisateur connecté, comme aujourd'hui. Aucune lecture ESI nouvelle, pas de Mercure.

## Frontend

7. Store : champs ajoutés au type `Colony`.
8. Carte de colonie (`PiColonyCard`) : deux jauges CPU et PG (`utilisé / max`, libre = max − utilisé, pourcentage). Cyan sous 85 %, ambre à partir de 85 %, rouge au-dessus de 100 % (libre négatif affiché tel quel). Infobulle : « liens non comptés, gardez une marge ». Inconnu : « inconnu » sans jauge, jamais 0.
9. Bloc « Command center » du détail déplié : `Niveau N`, `skill Command Center Upgrades du personnage : <romain ou chiffre>`, et le signal « CC à monter » quand `commandCenterUpgradable` est vrai (badge ambre dans la ligne repliée aussi). Skill inconnu : « skill non synchronisé » avec la même explication que #1384, pas de signal.
10. i18n FR et EN (`pi.power.*`), aucune clé supprimée, noms longs testés, direction HUD EVE.

## Exemples chiffrés

| Colonie | CC | Pins | Max CPU / PG | Utilisé |
|---|---|---|---|---|
| Barren niveau 5 | Elite Barren | 1 launchpad, 2 usines de base (200 CPU, 800 PG chacune) | 25415 / 19000 | 4000 / 2300 |
| Ice niveau 0 | Ice Command Center | 1 ECU 5 têtes (400 + 5 × 110 CPU, 2600 + 5 × 550 PG) | 1675 / 6000 | 950 / 5350 |
| Pin inconnu | n'importe quel niveau | un type sans `cpuLoad` | connu | `null` |

Skill 4 et niveau 3 : à monter. Skill 3 et niveau 3 : non. Pas de ligne de skill : `null`.

## Tests

- PHP : calculateur (sortie par niveau 0 et 5, type de niveau absent, charge avec ECU et têtes, pin sans attribut, sans command center, `null` partout), mapper ou ressource (champs présents sur la liste, `commandCenterUpgradable` vrai, faux, `null`), sync des skills (2505 écrit à 0, listes exhaustives voisines).
- Front : jauge normale, ambre, rouge, inconnu ; signal « CC à monter » ; skill inconnu. Au plus 12 tests.

## Hors périmètre

Filtre rapide « CC à monter » et tri (#1383), coût des liens, plan de planète du Planificateur (#1403), alerte.

## Décisions

- Niveau 0 = type de base du pin : c'est ainsi que l'ESI donne `upgrade_level` 0 et que la sortie de la maquette (niveau 5 = 25415 / 19000) se retrouve dans l'SDE.
- Liens non comptés, dit sur l'écran, plutôt qu'une longueur estimée sans donnée.
