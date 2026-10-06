# Baseline qualité — Phase 0

Mesures prises le **2026-10-06** sur `main` (`59a2bee`), avant toute modification du code de production. Elles servent de référence au cliquet de l'ADR-0005 : un périmètre ne doit jamais repasser sous ces valeurs.

Environnement : PHP 8.5.2 (FrankenPHP, image `evetools-app-dev` avec pcov), PHPUnit 12.5.11, Infection 0.35.6, deptrac 4.7.2, PHPStan 2.x niveau 8, Node 24 (hôte), trivy 0.75.0.

## Tests

| Suite | Tests | Assertions | Échecs |
|---|---|---|---|
| Unit | 778 | 2165 | **4** |
| Integration | 0 | — | — |
| Functional | 0 | — | — |

- Les 4 échecs existent déjà sur `main`, tous dans `MercurePublisherServiceTest` (`testPublish{Alert,Notification,SyncProgress,EscalationEvent}CatchesExceptionAndLogsWarning`). Ces tests attendent le message de log `'Failed to publish Mercure update'`, alors que le code logue désormais `'… : Connection refused'`. Ils sont exclus d'Infection via `testFrameworkOptions` dans `infection.json5`, à retirer une fois le test corrigé.
- 183 notices PHPUnit.
- Les suites Integration et Functional sont déclarées dans `phpunit.xml.dist`, mais elles sont vides.

```bash
docker compose exec app php vendor/bin/phpunit --no-coverage --testsuite=Unit
```

## Couverture de lignes

Globale : **19,63 %** des lignes (4734 / 24120), 8,62 % des méthodes, 3,05 % des classes.

| Périmètre | Lignes couvertes | % |
|---|---|---|
| `src/Service/GroupIndustry` | 352 / 358 | 98,3 |
| `src/Service/Planetary` | 141 / 147 | 95,9 |
| `src/Service/Mercure` | 107 / 142 | 75,4 |
| `src/Service/Admin` | 261 / 350 | 74,6 |
| `src/Service/Industry` | 2179 / 3056 | 71,3 |
| `src/Service/Notification` | 36 / 86 | 41,9 |
| `src/Service/Sync` | 503 / 1500 | 33,5 |
| `src/Service/JitaMarketService.php` | 124 / 491 | 25,3 |
| `src/Service/ESI` | 19 / 812 | 2,3 |
| `src/Service/Sde` | 0 / 930 | 0 |
| `src/Service/StructureMarketService.php` | 0 / 211 | 0 |
| `src/Service/OreValueService.php` | 0 / 130 | 0 |
| `src/Security` | 48 / 49 | 98,0 |
| `src/Controller` | 60 / 148 | 40,5 |
| `src/MessageHandler` | 111 / 720 | 15,4 |
| `src/State` | 534 / 7302 | 7,3 |
| `src/Entity` | 75 / 2267 | 3,3 |
| `src/ApiResource` | 0 / 1839 | 0 |
| `src/Repository` | 0 / 1886 | 0 |
| `src/Command` | 0 / 1304 | 0 |

```bash
docker compose exec app php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover=var/clover.xml \
  --exclude-filter='MercurePublisherServiceTest::testPublish.+CatchesExceptionAndLogsWarning'
```

## Mutation testing (MSI)

- **MSI** : part des mutants tués, sur tous les mutants générés.
- **Covered MSI** : la même part, mais seulement sur le code exécuté par au moins un test.

Les mesures sont faites avec `--with-uncovered`. Sans cette option, Infection 0.35 ignore le code non couvert et affiche une couverture trompeuse de 100 %.

| Périmètre | Mutants | Tués | Échappés | Non couverts | MSI | Covered MSI | Durée |
|---|---|---|---|---|---|---|---|
| `src/Service/Industry` | 3349 | 1258 | 1236 | 855 | **37 %** | **50 %** | 126 s |
| `src/Service/GroupIndustry` | 290 | 227 | 54 | 5 | **79 %** | **81 %** | 12 s |
| `src/Service/Planetary` | 173 | 82 | 86 | 5 | **47 %** | **48 %** | 6 s |
| `src/Service/Mercure` | 94 | 60 | 4 | 30 | **63 %** | **93 %** | 5 s |
| `src/Service/Notification` | 86 | 22 | 12 | 52 | **25 %** | **64 %** | 4 s |
| `src/Service/Sync` | 1479 | 227 | 234 | 1018 | **15 %** | **49 %** | 34 s |
| `src/Service/Admin` | 189 | 26 | 93 | 70 | **13 %** | **21 %** | 6 s |
| `src/Service/ESI` | 779 | 18 | 3 | 758 | **2 %** | 85 % | 8 s |
| `src/Service/Sde` | 1212 | 0 | 0 | 1212 | **0 %** | — | 10 s |
| `src` (global) | 19988 | 2737 | 2056 | 15191 | **13 %** | **57 %** | 304 s |

- 4 mutants en erreur dans le run global, et 4 dans le run GroupIndustry. Le log texte d'Infection ne détaille pas les mutants en erreur, donc le fait que ce soient les mêmes reste inconnu.
- Run global : il faut `-d memory_limit=2G`. Avec la limite par défaut de 512 Mo, il s'arrête au mutant n° 17 454.
- Industry : 71 % de couverture de lignes, mais 50 % de covered MSI. **La moitié des modifications du code couvert passent sans faire échouer un test.** C'est la cible prioritaire de la Phase 1.
- Planetary : 96 % de couverture et 48 % de covered MSI, le même symptôme.

```bash
docker compose exec app php vendor/bin/infection --threads=max --with-uncovered --filter=src/Service/<Dossier>
# ou : make infection FILTER=src/Service/<Dossier>
```

## Analyse statique

| Outil | Résultat |
|---|---|
| PHPStan niveau 8 (`src/`) | **63 erreurs** |
| deptrac (couches descriptives, mode rapport) | **36 violations**, 2807 dépendances autorisées, 4060 non couvertes (toutes vers `vendor/`) |

Détail des 36 violations deptrac :
- 29 : des processors (`src/State/Processor`) utilisent des `*ResourceMapper` rangés dans `src/State/Provider` ;
- 5 : Service dépend d'ApiResource ;
- 2 : Controller dépend de Message.

```bash
docker compose exec app php vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app php vendor/bin/deptrac analyse --no-progress   # ou : make deptrac
```

## Images Docker

| Image | Taille | Couches | CVE critiques | hautes | moyennes | basses | inconnues |
|---|---|---|---|---|---|---|---|
| `evetools-base:latest` | 289 Mo | 51 | 4 | 160 | 146 | 91 | 2 |
| `evetools-app:latest` | 371 Mo | 63 | 12 | 176 | 170 | 91 | 4 |
| proxy (`proxy/Dockerfile`) | 60,6 Mo | 25 | 6 | 81 | 78 | 59 | 2 |

- Le temps de build du proxy est de 41 s, avec le cache Docker local.
- `evetools-base` date de février 2026. `evetools-app` a été reconstruite le 2026-10-06.

```bash
docker image ls
docker history -q <image> | wc -l
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.75.0 image --quiet --format json <image> \
  | jq -r '[.Results[]?.Vulnerabilities[]?.Severity] | group_by(.) | map("\(.[0])=\(length)") | join(" ")'
```

## Frontend

| Mesure | Résultat |
|---|---|
| Vitest | 3 fichiers, **74 tests**, tous verts |
| ESLint | 0 erreur, **38 avertissements** |
| Build (`vue-tsc -b && vite build`) | OK |

- Le build modifie des fichiers versionnés (`frontend/dist/index.html`, `frontend/tsconfig.tsbuildinfo`) alors qu'ils figurent dans le `.gitignore`. Ce ménage est prévu en Phase 2.

```bash
npm --prefix frontend run test && npm --prefix frontend run lint && npm --prefix frontend run build
```
