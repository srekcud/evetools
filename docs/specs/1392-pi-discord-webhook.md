# Spec #1392 — Webhook Discord pour les alertes PI

Mode nuit : validée en autonomie. Décision de Sylvain (2026-11-26) : webhook Discord seul, le flux .ics est abandonné ; hôte Discord imposé (SSRF), URL chiffrée au repos, envoi par le dispatcher de notifications pour la catégorie `planetary`. Empilée sur #1391 (PR #1421). Migration (nouvelle table), nouvelle ressource API exposant une donnée secrète : technicité 2, revue `security-reviewer` obligatoire.

## But

Un joueur veut recevoir ses alertes PI (launchpad vide, stockage plein, donnée périmée, extracteur fini, #1391 et existantes) sur un salon Discord, sans ouvrir l'application.

## Stockage

Entité `UserDiscordWebhook` (table `user_discord_webhooks`) : `id` uuid, `user` (OneToOne unique, `onDelete: CASCADE`), `urlEncrypted` (texte), `createdAt`. Un webhook par utilisateur. L'URL claire n'est jamais stockée, jamais journalisée, jamais renvoyée par l'API.

## Règles backend

1. **Validation** (`DiscordWebhookUrlValidator`, fonction pure, aucune requête réseau) : une URL est acceptée si et seulement si : chaîne de 512 caractères au plus, sans espace, sans caractère de contrôle, sans `\` ni `@` ; schéma `https` ; hôte exact parmi `discord.com`, `canary.discord.com`, `ptb.discord.com`, `discordapp.com` (constante nommée, comparaison exacte insensible à la casse : `evil-discord.com`, `discord.com.evil.io`, `discord.com:8080@evil.io` refusés) ; ni identifiants, ni port autre que 443 ; ni requête ni fragment ; chemin `/api/webhooks/{id}/{token}` ou `/api/v{n}/webhooks/{id}/{token}`, `id` 17 à 20 chiffres, `token` 20 à 100 caractères `[A-Za-z0-9_-]`. L'URL retenue est reconstruite à partir des parties parsées (`https://<hôte en minuscules><chemin>`).
2. **Chiffrement** (`DiscordWebhookUrlCipher`) : `sodium_crypto_secretbox`, nonce aléatoire préfixé, base64, comme `TokenManager::encryptRefreshToken()` ; clé dérivée de la clé applicative existante (paramètre `esi_token_encryption_key`) par `sodium_crypto_generichash` avec un contexte fixe « discord-webhook-url » (séparation de domaine), pas de nouvelle variable d'environnement. `TokenManager` n'est pas modifié. Un texte altéré ou une mauvaise clé : exception explicite, jamais une chaîne vide.
3. **Envoi** (`DiscordWebhookSender::send(User $user, string $title, string $message): void`) : sans webhook enregistré, ne fait rien. Sinon déchiffre, `POST` JSON `{"content": "**<title>**\n<message>", "allowed_mentions": {"parse": []}}` (contenu tronqué à 2 000 caractères, aucune mention possible) via `HttpClientInterface`, avec `max_redirects: 0`, `timeout` et `max_duration` de 5 s, aucune en-tête d'autorisation. Une réponse hors 2xx (dont 429 et 404) lève une exception dont le message contient le code HTTP et jamais l'URL.
4. **Dispatcher** : `NotificationDispatcher::dispatch()`, après la notification Mercure et le Web Push, appelle `DiscordWebhookSender` quand la catégorie vaut `Notification::CATEGORY_PLANETARY` et que la préférence de la catégorie n'est pas désactivée (le retour anticipé existant couvre ce cas). Toute exception est attrapée et journalisée en avertissement (`userId`, catégorie, message d'erreur sans URL) : un échec Discord ne casse jamais la notification ni l'appelant. Les autres catégories ne déclenchent aucun envoi.
5. **API** (API Platform, `ROLE_USER`, `DiscordWebhookResource`, id fixe `discord-webhook`, champ `configured: bool`, aucun champ d'URL) :
   - `GET /me/discord-webhook` : `configured`.
   - `PUT /me/discord-webhook` corps `{url}` : valide (règle 1), 422 avec un message précis si invalide, chiffre, crée ou remplace, rend `configured: true`.
   - `DELETE /me/discord-webhook` (avec provider, 204) : supprime ; sans webhook, no-op 204.
   - `POST /me/discord-webhook/test` (`input: EmptyInput::class`) : envoie « Test » via le sender ; 422 « aucun webhook » s'il n'y en a pas ; 502 avec le code HTTP de Discord si l'envoi échoue ; 200 sinon.
   Toujours scopé à l'utilisateur courant ; 401 sans jeton.
6. **Aucun Mercure, aucun ESI, aucune tâche planifiée.** Le dispatcher publie déjà la notification ; l'envoi Discord est synchrone et borné à 5 s.

## Règles front (Paramètres, notifications)

7. Un bloc « Discord » dans `NotificationPreferencesForm.vue` : champ d'URL (type mot de passe, jamais prérempli), boutons Enregistrer, Tester, Retirer, et l'état « configuré » / « non configuré ». Enregistrer désactivé tant que le champ est vide ; une URL refusée par le backend affiche son message sous le champ (`ErrorBanner` pour les autres erreurs). Tester et Retirer visibles seulement si configuré. Après un enregistrement, le champ est vidé.
8. Store `discordWebhook` (`fetchStatus`, `save(url)`, `remove()`, `sendTest()`, `isLoading`, `error`). Un test réussi affiche une confirmation ; un échec affiche le message du backend.
9. i18n FR et EN (`notificationHub.discord.*`), parité vérifiée par un test de clés. Texte d'aide : le webhook est un secret, il est chiffré, la catégorie PI est la seule envoyée.
10. Glossaire : « Webhook Discord » / « Discord webhook ».

## Exemples (validateur)

| URL | Résultat |
|---|---|
| `https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789xyz` | acceptée, inchangée |
| `https://DISCORD.com/api/v10/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789xyz` | acceptée, hôte en minuscules |
| `http://discord.com/api/webhooks/…` | refusée (schéma) |
| `https://discord.com.evil.io/api/webhooks/…` | refusée (hôte) |
| `https://discord.com@evil.io/api/webhooks/…` | refusée (`@`) |
| `https://discord.com:8443/api/webhooks/…` | refusée (port) |
| `https://discord.com/api/webhooks/…?wait=true` | refusée (requête) |
| `https://discord.com/api/webhooks/12/short` | refusée (id et jeton trop courts) |
| `https://discord.com/other/path` | refusée (chemin) |
| chaîne vide, 513 caractères, URL avec `\` ou espace | refusée |

## Cas limites

- Un joueur qui remplace son webhook : l'ancienne valeur est écrasée (une seule ligne par utilisateur).
- Suppression du compte : la ligne part en cascade.
- Un webhook révoqué côté Discord (404) : le test affiche l'échec, les alertes continuent d'être créées dans l'application ; le joueur retire ou remplace le webhook.

## Hors périmètre

- Flux .ics (abandonné). Autres catégories de notification vers Discord. Format riche (embeds), file de réessai, limite de débit côté application, désactivation automatique après échecs répétés. Rotation de la clé applicative (elle rechiffrerait aussi les tokens ESI).

## Décisions prises en autonomie

- Dérivation de clé avec contexte plutôt qu'une nouvelle variable d'environnement : aucun secret à déployer, et la compromission d'un usage n'ouvre pas l'autre par simple réutilisation du chiffre brut.
- Hôtes `canary.discord.com`, `ptb.discord.com` et `discordapp.com` autorisés en plus de `discord.com` (formes officielles des URL de webhook) ; à resserrer si Sylvain préfère `discord.com` seul.
- Le constructeur de `NotificationDispatcher` gagne une dépendance : ses deux tests existants sont adaptés dans le lot rouge.

## Tests

- Unit : validateur (tableau ci-dessus), chiffrement (aller-retour, texte altéré, autre clé), sender (corps exact, `allowed_mentions`, troncature, redirections désactivées, 429/404/500 lèvent sans URL dans le message, sans webhook ne fait rien), dispatcher (planetary envoie, autre catégorie non, préférence désactivée non, échec du sender n'interrompt pas).
- Fonctionnel API : 401 ; PUT invalide 422 ; PUT valide, GET `configured` true, valeur en base différente de l'URL claire ; DELETE ; isolation entre deux utilisateurs.
- vitest : store, bloc du formulaire (états, message du backend, champ vidé), parité i18n.

## Note de câblage

Backend : `src/Entity/UserDiscordWebhook.php` + dépôt + migration ; `src/Service/Notification/{DiscordWebhookUrlValidator,DiscordWebhookUrlCipher,DiscordWebhookSender}.php` ; `src/ApiResource/Notification/DiscordWebhookResource.php`, `src/ApiResource/Input/Notification/DiscordWebhookInput.php`, `src/State/{Provider,Processor}/Notification/…`. Modèle d'API : `NotificationPreferencesResource` et `/me/push-subscription`. Chiffrement : `TokenManager::encryptRefreshToken()` / `getKey()` (lecture seule). Migration validée sur base isolée (`TEST_TOKEN=n1392`) : `schema:validate` obligatoire. Action prod : migration seulement.
Front : `frontend/src/stores/discordWebhook.ts`, bloc dans `NotificationPreferencesForm.vue` (ou composant `DiscordWebhookSettings.vue` monté dedans), clés `notificationHub.discord.*`.
