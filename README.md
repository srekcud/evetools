# EVE Tools - EVE Online Utilities Application

Application web d'utilitaires pour le jeu EVE Online permettant de gérer des flottes de minage, des projets industriels, calculer des gains PVE et lancer des alertes intel.

## Stack Technique

- **Backend**: Symfony 7.2 + API Platform 3.x
- **Runtime PHP**: FrankenPHP (PHP 8.4 Alpine)
- **Base de données**: PostgreSQL 16
- **Queue**: RabbitMQ
- **Cache**: Redis
- **Auth**: JWT (lexik/jwt-authentication-bundle) + OAuth2 EVE ESI

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
2. Configurer les scopes requis:
   - `esi-assets.read_assets.v1`
   - `esi-assets.read_corporation_assets.v1`
   - `esi-characters.read_corporation_roles.v1`
   - `esi-corporations.read_divisions.v1`
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
make test          # Lancer les tests
make db-migrate    # Lancer les migrations
make messenger     # Démarrer le consumer messenger
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
- `POST /api/me/characters/add` - Ajouter un alt
- `DELETE /api/me/characters/{id}` - Supprimer un alt
- `POST /api/me/characters/{id}/set-main` - Définir le main

### Assets
- `GET /api/me/characters/{id}/assets` - Assets personnels
- `POST /api/me/characters/{id}/assets/refresh` - Forcer refresh
- `GET /api/me/corporation/assets` - Assets corporation
- `POST /api/me/corporation/assets/refresh` - Forcer refresh corp

### Corporation
- `GET /api/me/corporation` - Infos corporation + divisions

## Tests

```bash
make test          # Tous les tests
make test-unit     # Tests unitaires uniquement
make test-coverage # Tests avec couverture
```

## Architecture

```
src/
├── ApiResource/     # Resources API Platform
├── Controller/      # Controllers Symfony
├── Dto/             # Data Transfer Objects
├── Entity/          # Entities Doctrine
├── EventListener/   # Event Listeners
├── Exception/       # Exceptions personnalisées
├── Message/         # Messages Messenger
├── MessageHandler/  # Handlers Messenger
├── Repository/      # Repositories Doctrine
├── Scheduler/       # Scheduler Symfony
├── Security/        # Voters et listeners sécurité
├── Service/         # Services métier
│   ├── ESI/         # Services ESI (API EVE)
│   └── Sync/        # Services de synchronisation
└── State/           # Providers et Processors API Platform
```

## License

Proprietary
