# #359 lot 2c : page Compétences

Maquette `industry-project-v3.html#p=38` (note 28). Dernier lot de #359 : la page sur le socle des lots 2a, 2b-1 et 2b-2. Décisions de Sylvain (2026-11-09) : un personnage à la fois avec un sélecteur, route dédiée sous Industrie avec un bouton « Compétences » dans l'Atelier, tri par gain par jour d'entraînement, inconnus en fin de liste avec leur cause. Mode nuit : validée en autonomie.

## Règles

1. **Backend** : chaque ligne de `GET /api/me/characters/{characterId}/skill-gains` gagne `skillName` (`TypeNameResolver::findNames`, `null` si le nom est inconnu). Rien d'autre ne change.
2. **Route** : `/industry/skills`, nom `industry-skills`, `requiresAuth`, vue `views/IndustrySkills.vue`. Bouton « Compétences » (`data-testid="workshop-skills-link"`) dans l'en-tête de la file d'actions de `IndustryWorkshop.vue`, qui navigue vers `{ name: 'industry-skills' }`.
3. **Store** `stores/industry/skillGains.ts` (`useSkillGainsStore`) : `skillGains` (`SkillGains | null`), `isLoading`, `error`, `fetchSkillGains(characterId)` ; une réponse périmée (autre personnage demandé entre-temps) est ignorée ; le changement de personnage vide la liste affichée avant le chargement.
4. **Vue** : en-tête (kicker « Production · {personnage} », titre, sous-titre, lien « ← Atelier » vers la route `industry`), sélecteur des personnages de l'utilisateur (`authStore.user.characters`, principal par défaut, `data-testid="skills-character-select"`), carte « Les plus rentables à apprendre » avec une pastille « valorisé à {ISK/h} ISK/h sur 30 jours » (inconnue si l'ISK/h est `null`), `SkillGainsTable`, note de bas de carte (hors implants et hors datacores, durée depuis les points déjà entraînés). Erreur de chargement : `ErrorBanner`. Chargement : `LoadingSpinner`. Aucune ligne : « Aucune compétence à entraîner pour ce personnage ».
4b. Sans personnage : message d'état vide, aucune requête.
5. **Tableau** `components/industry/SkillGainsTable.vue` (props `lines`), une ligne par entrée dans l'ordre reçu : compétence (`skillName`, sinon « Compétence #id », tronquée avec titre complet) et « niveau → niveau+1 », effet (libellé de `effect`), durée d'entraînement (`formatDuration`), gain par mois (`IskAmount`, 0 affiché comme 0), gain par jour d'entraînement (gras). Une valeur `null` s'affiche avec sa cause en violet pointillé (`SellMarketUnknownValue`), jamais `0` ni `—` seul : cause de `gainUnknownReason` pour le gain par mois et le gain par jour (le gain par jour inconnu avec un gain connu prend la cause de la durée), cause de `trainingUnknownReason` pour la durée. Identifiants de test : `skill-gain-row`, `skill-gain-name`, `skill-gain-training`, `skill-gain-monthly`, `skill-gain-per-day`.
6. **i18n** FR et EN sous `industry.skills.*` : libellés, effets (`manufacturing_time`, `reaction_time`, `invention_chance`, `manufacturing_slot`, `reaction_slot`, `science_slot`), causes (`skills_not_synchronized`, `project_jobs_not_cached`, `isk_per_hour_unknown`, `attributes_not_synchronized`, `skill_not_in_sde`, `skill_points_unknown`, `active_level_below_trained_level`, `max_level_reached`).
7. Direction HUD EVE (composants et couleurs existants), noms longs tronqués, pas de Mercure (lecture seule).

## Exemples

| # | Entrée | Affichage |
|---|---|---|
| E1 | ligne Industry 2 → 3, gain 5 217 391, durée 4 j, gain/jour 1 304 348 | « Industry 2 → 3 », « Temps de fabrication », « 4d », montants |
| E2 | `gainUnknownReason = isk_per_hour_unknown` | gain et gain/jour : « ISK/h inconnu » en violet pointillé |
| E3 | gain connu, `trainingUnknownReason = attributes_not_synchronized` | durée : « Attributs non synchronisés », gain/jour : même cause |
| E4 | gain 0, durée connue | gain « 0 », gain/jour « 0 » |
| E5 | changement de personnage pendant un chargement | la réponse du premier est ignorée |

## Hors périmètre

Skill Planner, file d'entraînement, plusieurs niveaux à la fois, export, implants.
