# #359 lot 2b-2 : gain par mois de chaque compétence

Suite des lots 2a et 2b-1. Décisions de Sylvain (2026-11-09) : compétences de temps, Science et invention, et slots ; temps gagné valorisé à l'ISK/h de `GET /api/ledger/industry?days=30` ; un slot de plus = un slot moyen (heures de la catégorie divisées par le nombre de slots). Mode nuit : validée en autonomie.

## Règles

1. **Entrée** : un personnage de l'utilisateur, les 30 derniers jours (`[now − 30 j, now]`, comme l'onglet Industrie des Revenus). Jobs retenus : jobs liés aux projets terminés dans la période où l'utilisateur est participant accepté (`IndustryProjectParticipantRepository::findAcceptedOnProjectsCompletedBetween`), dont le lien porte le nom du personnage, dédoublonnés par `esiJobId`. Heures d'un job = `endDate − startDate`. Un job lié absent du cache : gains inconnus (`ProjectJobsNotCached`).
2. **ISK/h** : `IndustryRevenueReader::reportFor` sur la même période, `totals.iskPerHour` ; `null` : gains inconnus (`IskPerHourUnknown`).
3. **Niveaux** : `CachedCharacterSkill` du personnage. Aucune compétence en cache : gains inconnus (`SkillsNotSynchronized`) ; sinon une compétence absente est au niveau 0. Une compétence au niveau 5 n'a pas de ligne. Ordre des causes : compétences non synchronisées, jobs non cachés, ISK/h inconnu.
4. **Compétences de temps** (domaine pur `SkillGain::timeSavedHours`) : heures gagnées par mois = heures × `r / (1 − r × N)`, N niveau actuel, r réduction par niveau de `SkillTimeModifier` (Industry 0,04 et Advanced Industry 0,03 sur les jobs de fabrication, activité 1 ; Reactions 0,04 sur les réactions, activités 9 et 11).
5. **Invention** (`SkillGain::inventionSavedHours`) : pour chaque job d'invention (activité 8), compétences exigées par `InventionSkillRequirements::of(blueprintTypeId)` ; une ligne par compétence exigée par au moins un job ; heures gagnées = somme sur les jobs qui l'exigent de `heures × (1 − chance(N) / chance(N+1))`, `chance` = `InventionChance::of` avec les niveaux du personnage (autres compétences au niveau actuel). Coût des datacores non compté.
6. **Slots** (`SkillGain::averageSlotHours`) : Mass Production et Advanced Mass Production (fabrication), Mass Reactions et Advanced Reactions (réactions), Laboratory Operation et Advanced Laboratory Operation (science, activités 3, 4, 5, 7, 8) ; heures de la catégorie (`SlotTrackerService::getSlotCategory`) divisées par le nombre de slots de la catégorie du personnage (`SlotTrackerService::calculateMaxSlots`) ; un slot de plus rapporte autant qu'un slot moyen.
7. **Gain par mois** = heures gagnées × ISK/h ; 0 heure gagnée donne un gain de 0 (donnée réelle, pas inconnue). **Gain par jour d'entraînement** = gain par mois / (durée du niveau suivant en jours) ; `null` si le gain ou la durée sont inconnus.
8. **Durée** : `SkillTrainingEstimator::nextLevel` avec les points de la compétence (`null` pour une ligne manuelle, 0 pour une compétence absente d'un personnage synchronisé).
9. **Ligne** (`SkillGainLine`) : `skillId`, `currentLevel`, `targetLevel`, `effect` (`manufacturing_time`, `reaction_time`, `invention_chance`, `manufacturing_slot`, `reaction_slot`, `science_slot`), `hoursSavedPerMonth`, `monthlyGain`, `gainUnknownReason`, `trainingSeconds`, `trainingUnknownReason`, `gainPerTrainingDay`. Tri : gain par jour décroissant, puis gain par mois inconnu à la fin.
10. **Ressource** `SkillGainsResource` : `GET /api/me/characters/{characterId}/skill-gains`, `ROLE_USER`, 401 sans authentification, 404 pour un personnage qui n'est pas à l'utilisateur, `skip_null_values=false`. Corps : `characterId`, `period {from, to, days}`, `iskPerHour`, `lines`. Calculée à la lecture, rien de persisté. Mercure : aucune action asynchrone.

## Exemples

| # | Entrée | Résultat |
|---|---|---|
| E1 | Industry niveau 2, 100 h de fabrication | 100 × 0,04 / (1 − 0,08) = 4,348 h gagnées |
| E2 | Reactions niveau 0, 50 h de réaction | 2 h |
| E3 | invention, science (3 + 3), encryption 2, un job de 10 h, science +1 | chance ×(1 + 7/30 + 2/40)/(1 + 6/30 + 2/40) : 10 × (1 − 1,25/1,2833) h = 0,260 h |
| E4 | 2 slots de fabrication, 120 h de fabrication | un slot de plus : 60 h |
| E5 | 4,348 h gagnées, ISK/h 1 200 000 | 5 217 391 ISK par mois |
| E6 | job lié non caché | tous les gains `ProjectJobsNotCached`, durées inchangées |
| E7 | ISK/h `null` | tous les gains `IskPerHourUnknown` |
| E8 | personnage d'un autre utilisateur | 404 |

## Hors périmètre

Compétences de science exigées par les blueprints de fabrication, coût des datacores, structures et implants, page (lot 2c), plusieurs niveaux à la fois, compétences de l'invention sans job d'invention le mois dernier.
