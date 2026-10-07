# EVE Tools - EVE Online Utilities Application

Application web d'utilitaires pour le jeu EVE Online permettant de gérer des flottes de minage, des projets industriels, calculer des gains PVE et lancer des alertes intel.

## Stack Technique

- **Backend**: Symfony 7.4 LTS + API Platform 4.2
- **Frontend**: Vue.js 3.5 + Vite + Tailwind CSS 4
- **Runtime PHP**: FrankenPHP (PHP 8.5 Alpine)
- **Base de données**: PostgreSQL 16
- **Queue**: RabbitMQ (Symfony Messenger)
- **Cache**: Redis
- **Temps réel**: Mercure (intégré à FrankenPHP)
- **Auth**: JWT (lexik/jwt-authentication-bundle) + OAuth2 EVE SSO

## Prérequis

- Docker et Docker Compose
- (Optionnel) Make

## Installation

1. Cloner le repository
```bash
git clone <repository-url>
cd evetools
```

2. Copier et configurer les variables d'environnement
```bash
cp .env.local.example .env.local
# Éditer .env.local avec vos credentials EVE ESI
```

3. Construire et démarrer les containers
```bash
make build
make up
```

4. Installer les dépendances
```bash
make install
```

5. Générer les clés JWT
```bash
make jwt-keys
```

6. Créer la base de données et lancer les migrations
```bash
make db-create
make db-migrate
```

## Configuration EVE ESI

1. Créer une application sur https://developers.eveonline.com/
2. Configurer les scopes requis : les 27 scopes listés dans `AuthenticationService::REQUIRED_SCOPES` (`src/Service/ESI/AuthenticationService.php`). L'application les demande tous à la connexion ; un scope absent de l'application EVE fait échouer l'autorisation.
3. Copier le Client ID et Client Secret dans `.env.local`
4. Générer une clé de chiffrement pour les tokens:
```bash
php -r "echo base64_encode(random_bytes(32));"
```

## Commandes utiles

```bash
make help          # Afficher l'aide
make up            # Démarrer les containers
make down          # Arrêter les containers
make logs          # Voir les logs
make shell         # Shell dans le container app
make db-migrate    # Lancer les migrations
make db-diff       # Générer une migration depuis les entités
make sde-import    # Importer le Static Data Export d'EVE
make messenger     # Démarrer le consumer messenger (transport async)
make scheduler     # Démarrer le consumer du scheduler
```

## API Endpoints

### Auth
- `GET /auth/eve/redirect` - Obtenir l'URL de redirection EVE OAuth
- `GET /auth/eve/callback` - Callback OAuth
- `POST /auth/refresh` - Rafraîchir le JWT
- `POST /auth/logout` - Déconnexion

### Users
- `GET /api/me` - Infos utilisateur courant

### Characters
- `GET /api/me/characters` - Liste des characters
- `DELETE /api/me/characters/{id}` - Supprimer un alt
- `POST /api/me/characters/{id}/set-main` - Définir le main

Un alt s'ajoute en repassant par le flux OAuth (`/auth/eve/redirect` puis `/auth/eve/callback`) avec un utilisateur déjà connecté.

### Assets
- `GET /api/me/characters/{characterId}/assets` - Assets personnels
- `POST /api/me/characters/{characterId}/assets/refresh` - Forcer refresh
- `GET /api/me/corporation/assets` - Assets corporation
- `POST /api/me/corporation/assets/refresh` - Forcer refresh corp
- `GET|PUT /api/me/corporation/assets/visibility` - Divisions corporation visibles par les membres (modification réservée aux Directors)

La liste complète des endpoints est dans la documentation OpenAPI générée par API Platform (`/api/docs`).

## Tests

```bash
make test             # Toutes les suites (Unit, Integration, Functional), sans couverture
make test-unit        # Suite Unit uniquement
make test-db          # (Re)crée la base de test eve_app_test et son schéma
make test-integration # Suite Integration (nécessite make test-db)
make test-coverage    # Tests avec couverture HTML dans var/coverage
make infection        # Mutation testing, rapport dans var/infection/ (FILTER=src/... pour restreindre)
make deptrac          # Rapport de dépendances entre couches (n'échoue jamais)
```

`make test` lance aussi la suite Integration : créez d'abord la base de test avec `make test-db`. Le schéma de test est construit depuis le mapping des entités, car les migrations ne se rejouent pas sur une base vide.

## Architecture

```
src/
├── ApiResource/     # Resources API Platform
├── Command/         # Commandes console
├── Constant/        # Constantes métier
├── Controller/      # Controllers Symfony
├── DataFixtures/    # Fixtures Doctrine
├── Dto/             # Data Transfer Objects
├── Entity/          # Entities Doctrine (dont Sde/)
├── Enum/            # Enums
├── EventListener/   # Event Listeners
├── Exception/       # Exceptions personnalisées
├── Message/         # Messages Messenger
├── MessageHandler/  # Handlers Messenger
├── Repository/      # Repositories Doctrine
├── Scheduler/       # Scheduler Symfony
├── Security/        # Voters et listeners sécurité
├── Service/         # Services métier
│   ├── Admin/
│   ├── ESI/         # Services ESI (API EVE)
│   ├── GroupIndustry/
│   ├── Industry/
│   ├── Mercure/
│   ├── Notification/
│   ├── Planetary/
│   ├── Sde/
│   └── Sync/        # Services de synchronisation
└── State/           # Providers et Processors API Platform
```

## License

Proprietary
