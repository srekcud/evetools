# #304 lot 2 : « Nouveau site », isolation des chargements, options opt-in du compagnon

Front seul (aucune route, migration ni entité). Suite de `304-mining-companion-lot1.md` (PR #515 fusionnée). Prérequis vérifiés sur origin/main : `miningSite.startNextSite(operationId, {label, solarSystemId})` (POST `/api/mining-operations/{operationId}/next-site`, clôture les sites actifs, ouvre un site lié, 403 sans effet si un site actif appartient à un autre participant non admin), événement Mercure `site_next_started` `{siteId, closedSiteIds}`, `MiningSolarSystemSearch`, `MiningCompanionHost`/`Panel`/`Settings`, store `miningCompanion`, `parseSurveyPaste`, `isEverythingDepleted`.

## Périmètre

Livré : (A) « Nouveau site » dans le compagnon ; (B) point 6 du lot 1, isolation des chargements du compagnon ; (C) options opt-in, notification navigateur « site vide » et lecture du presse-papiers au retour sur la fenêtre. Hors lot (Blocage au journal, décision produit) : contenu hors opération (prochain chunk #274, « Créer l'opération », « Estimer un site seul » #267) : le compagnon n'a aucun point d'entrée sans cible, et `BaseModal` se téléporte dans la fenêtre principale, pas dans la fenêtre détachée. L'issue #304 reste ouverte (pas de `Closes`).

## A. Nouveau site

1. **Visibilité** : bouton `companion-next-site` dans le pied du panneau, sous la même condition que « Je pars » (opération `in_progress`, `myDepartedAt === null`, cible = opération) et seulement hors taille `bar` (le pied n'existe pas en `bar`). Absent d'une cible site (pas d'opération chargée) : décision (1).
2. **Formulaire** : composant de présentation `MiningCompanionNextSite` (props `failure: string | null`, `submitting: boolean` ; événements `submit {label, solarSystemId}` et `cancel`), rendu par le panneau dans un slot `next-site`, ouvert par le clic sur le bouton (second clic ou `cancel` = fermé). Champs : libellé (`input[name="label"]`, 100 caractères au plus, comme `MiningSiteCreateModal`) et système (`MiningSolarSystemSearch`, optionnel). Test-ids : `companion-next-site-form`, `companion-next-site-submit`, `companion-next-site-cancel`, `companion-next-site-error`. Le texte `miningSite.create.closesActive` (existant) prévient que les sites actifs seront clos. Libellé vide : message `miningSite.create.labelRequired` (existant), rien n'est émis.
3. **Hôte** : sur `submit`, `siteStore.startNextSite(operationId, {label, solarSystemId})`. Succès : formulaire fermé, message `miningCompanion.nextSite.started` (libellé du site), cible rechargée (`loadTarget`) : le panneau montre le nouveau site. Refus ou échec (403 inclus) : le message du serveur s'affiche dans le formulaire (`failure`), le formulaire reste ouvert, le site affiché ne change pas. Pas de navigation de la fenêtre principale.
4. **Événement `site_next_started`** de l'opération : cible = opération : `loadTarget` (comme `site_scan_recorded`). Cible = site dont l'id est dans `closedSiteIds` : le compagnon suit le nouveau site (`companion.retarget({siteId: data.siteId, operationId: cible.operationId})`, nouvelle action du store qui ne touche ni la taille ni la fenêtre). Cible = site absent de `closedSiteIds` : rien. Donnée de l'événement absente ou mal formée : rien (pas de retarget sur un id inconnu).

## B. Isolation des chargements du compagnon (point 6)

5. Nouvelles actions qui renvoient la donnée sans toucher à l'état de la page, et lèvent en cas d'échec : `miningOperation.readOperation(id): Promise<MiningOperation>` (GET `/mining-operations/{id}`), `miningOperation.leaveOperation(id): Promise<MiningOperation>` (POST `/mining-operations/{id}/depart`, même corps que `depart`), `miningSite.readOperationSites(operationId): Promise<MiningSite[]>` (GET `/mining-sites?operationId=`). Aucune n'écrit `currentOperation`, `operationSites` ni `error`. `fetchDetail`, `depart` et `fetchOperationSites` restent inchangés.
6. L'hôte utilise ces trois actions (chargement de l'opération, résolution du site actif, « Je pars ») au lieu de `fetchDetail` / `fetchOperationSites` / `depart`. Échec : message d'erreur de l'exception dans le panneau.
7. `startNextSite` ne recharge `operationSites` de la page que si cette liste concerne la même opération ou n'a jamais été chargée : le store garde `operationSitesOperationId` (posé par `fetchOperationSites`). Le test existant (requêtes `[POST next-site, GET sites]` sans chargement préalable) reste vrai.

## C. Options opt-in

8. **Réglages** (`MiningCompanionSettings`, mémorisés dans le navigateur, défaut `false`, lecture tolérante) : `companion-notifications-toggle` (clé `miningCompanion.notifications`) et `companion-clipboard-toggle` (clé `miningCompanion.clipboardOnFocus`). Props `notifications`, `clipboardOnFocus` ; événements `notifications`, `clipboard` (booléen). Store : `settings.notifications`, `settings.clipboardOnFocus`, actions `setNotifications(enabled)`, `setClipboardOnFocus(enabled)`.
9. **Activer les notifications** (clic de l'utilisateur) : `Notification.requestPermission()`. Accordée : réglage vrai. Refusée, ou `Notification` indisponible : réglage reste faux et message `miningCompanion.notices.notificationsDenied`. Désactiver ne demande rien.
10. **Notification « site vide »** : réglage vrai et permission `granted`, site actif de la cible dont le dernier scan est `isEverythingDepleted`, ET la transition « pas vide » vers « vide » a été observée dans la fenêtre pour ce site (le premier état observé d'un site sert de base : un site déjà vide à l'ouverture ne notifie pas). Une seule notification par site et numéro de scan : `new Notification(t('miningCompanion.notification.depletedTitle'), { body: libellé du site, tag: 'companion-depleted-<siteId>' })`. Réglage faux, permission absente ou aucun changement : aucune notification. Aucun son (`silent: true`).
11. **Presse-papiers au retour** : réglage vrai, événement `focus` de la fenêtre du compagnon, site actif chargé : `navigator.clipboard.readText()` de la fenêtre, puis `parseSurveyPaste`. Texte vide, ou qui ne ressemble pas à un scan (aucune ligne reconnue, ou au moins une erreur de ligne) : ignoré, aucun message, rien d'enregistré. Texte valide : `recordScan` puis message `miningSite.scan.added`, comme le collage manuel. Lecture refusée : message `miningCompanion.notices.clipboardDenied` (une fois par ouverture de la fenêtre).
12. **Jamais le même scan deux fois** : l'hôte garde en mémoire (pas dans le store, pas de stockage) une empreinte numérique du dernier texte enregistré, par le collage manuel ou au retour. Un retour avec le même texte est ignoré. Le texte lui-même n'est jamais conservé.

## Exemples chiffrés

| Cas | Attendu |
|---|---|
| libellé « Belt Jaspet », système aucun | `startNextSite(op, {label:'Belt Jaspet', solarSystemId:null})`, formulaire fermé, nouveau site affiché |
| `startNextSite` rejeté 403 | message du serveur dans `companion-next-site-error`, formulaire ouvert, site inchangé |
| cible site S1, événement `{siteId:S2, closedSiteIds:[S1]}` | `target.siteId` = S2 |
| cible site S1, événement `{siteId:S2, closedSiteIds:[S3]}` | `target.siteId` = S1 |
| page sur l'opération B, compagnon sur A | après chargement : `currentOperation` et `operationSites` de la page inchangés |
| site non vide puis vide, réglage vrai, permission accordée | 1 notification |
| site déjà vide à l'ouverture | 0 notification |
| retour sur la fenêtre, presse-papiers = scan valide, deux retours de suite | 1 `recordScan` |
| retour, presse-papiers = « bonjour » | 0 `recordScan`, aucun message |

## Cas limites

Aucun site actif après `startNextSite` (impossible côté API) : état vide existant. Opération passée hors `in_progress` pendant la saisie : le serveur refuse, message affiché. Double clic sur « valider » : `submitting` désactive le bouton.

## Accès et fraîcheur

Aucun contrôle nouveau : le serveur décide (403 pour un site actif d'un autre participant non admin, #305). Source de l'opération : GET `/mining-operations/{id}`, rechargé au changement de cible et sur événement.

## Décisions prises en autonomie

(1) « Nouveau site » seulement pour une cible opération : une cible site n'a pas d'opération chargée, et la charger ajoute un cas sans demande. (2) Le texte de la maquette « Annuler » après « Je pars » reste non livré (lot 1). (3) Le type de site (chunk/anomalie) n'est pas modélisé (#305) : le libellé le porte. (4) Pas de son, y compris `silent: true` sur la notification.

## Tests existants contredits ou modifiés

Aucun. `MiningCompanionPanel.spec.ts` et `miningSite.spec.ts` restent intacts (props et paramètres ajoutés optionnels).

## Glossaire

Aucun terme ajouté (Minage : « Compagnon », « Site », « Opération » existent).
