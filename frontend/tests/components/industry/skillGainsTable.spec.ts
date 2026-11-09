import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SkillGainsTable from '@/components/industry/SkillGainsTable.vue'
import type { SkillGainLine } from '@/stores/industry/skillGains'

const ROW = '[data-testid="skill-gain-row"]'
const NAME = '[data-testid="skill-gain-name"]'
const TRAINING = '[data-testid="skill-gain-training"]'
const MONTHLY = '[data-testid="skill-gain-monthly"]'
const PER_DAY = '[data-testid="skill-gain-per-day"]'
const UNKNOWN = '[data-testid="unknown-value"]'
const FOUR_DAYS_IN_SECONDS = 345_600
const LONG_SKILL_NAME = 'Advanced Planetology And Other Skills With A Very Long Name Nobody Reads'

function line(overrides: Partial<SkillGainLine>): SkillGainLine {
  return {
    skillId: 3380,
    skillName: 'Industry',
    currentLevel: 2,
    targetLevel: 3,
    effect: 'manufacturing_time',
    hoursSavedPerMonth: 4.35,
    monthlyGain: 5_217_391,
    gainUnknownReason: null,
    trainingSeconds: FOUR_DAYS_IN_SECONDS,
    trainingUnknownReason: null,
    gainPerTrainingDay: 1_304_348,
    ...overrides,
  }
}

function mountTable(lines: SkillGainLine[], locale: 'en' | 'fr' = 'fr'): VueWrapper {
  return mount(SkillGainsTable, {
    props: { lines },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

enableAutoUnmount(afterEach)

describe('SkillGainsTable', () => {
  it('lists one row per line in the order received with the skill, its levels, its effect, its training duration and its gains', () => {
    const wrapper = mountTable([
      line({}),
      line({ skillId: 45746, skillName: 'Reactions', currentLevel: 0, targetLevel: 1, effect: 'reaction_time', monthlyGain: 2_400_000, gainPerTrainingDay: 800_000, trainingSeconds: 3 * 86_400 }),
    ])

    const rows = wrapper.findAll(ROW)
    expect(rows).toHaveLength(2)
    expect(rows[0].get(NAME).text()).toContain('Industry')
    expect(rows[0].text()).toContain('2 → 3')
    expect(rows[0].text()).toContain('Temps de fabrication')
    expect(rows[0].get(TRAINING).text()).toBe('4d')
    expect(rows[0].get(MONTHLY).text()).toBe('5,22 M')
    expect(rows[0].get(PER_DAY).text()).toBe('1,30 M')
    expect(rows[1].get(NAME).text()).toContain('Reactions')
    expect(rows[1].text()).toContain('0 → 1')
    expect(rows[1].text()).toContain('Temps de réaction')
  })

  it('writes the effect of every kind of line in the language of the user', () => {
    const effects = ['manufacturing_time', 'reaction_time', 'invention_chance', 'manufacturing_slot', 'reaction_slot', 'science_slot'] as const

    const wrapper = mountTable(effects.map(effect => line({ effect })), 'en')

    expect(wrapper.findAll(ROW).map(row => row.findAll('td')[1].text())).toEqual([
      'Manufacturing time',
      'Reaction time',
      'Invention chance',
      'Manufacturing slot',
      'Reaction slot',
      'Science slot',
    ])
  })

  it('shows the cause of an unknown gain on the gain and the gain per day instead of zero', () => {
    const wrapper = mountTable([line({ monthlyGain: null, hoursSavedPerMonth: null, gainPerTrainingDay: null, gainUnknownReason: 'isk_per_hour_unknown' })])

    const row = wrapper.get(ROW)
    expect(row.get(MONTHLY).text()).toBe('ISK/h inconnu')
    expect(row.get(PER_DAY).text()).toBe('ISK/h inconnu')
    expect(row.get(MONTHLY).find(UNKNOWN).exists()).toBe(true)
    expect(row.get(TRAINING).text()).toBe('4d')
  })

  it('shows the cause of an unknown training duration, keeps the known gain and gives the same cause to the gain per day', () => {
    const wrapper = mountTable([line({ trainingSeconds: null, trainingUnknownReason: 'attributes_not_synchronized', gainPerTrainingDay: null })])

    const row = wrapper.get(ROW)
    expect(row.get(TRAINING).text()).toBe('Attributs non synchronisés')
    expect(row.get(MONTHLY).text()).toBe('5,22 M')
    expect(row.get(PER_DAY).text()).toBe('Attributs non synchronisés')
  })

  it('writes every unknown cause in French', () => {
    const gainCauses = ['skills_not_synchronized', 'project_jobs_not_cached', 'isk_per_hour_unknown'] as const
    const trainingCauses = ['attributes_not_synchronized', 'skill_not_in_sde', 'skill_points_unknown', 'active_level_below_trained_level', 'max_level_reached'] as const

    const gains = mountTable(gainCauses.map(gainUnknownReason => line({ monthlyGain: null, gainPerTrainingDay: null, gainUnknownReason })))
    const trainings = mountTable(trainingCauses.map(trainingUnknownReason => line({ trainingSeconds: null, gainPerTrainingDay: null, trainingUnknownReason })))

    expect(gains.findAll(ROW).map(row => row.get(MONTHLY).text())).toEqual([
      'Compétences non synchronisées',
      'Jobs de projet non synchronisés',
      'ISK/h inconnu',
    ])
    expect(trainings.findAll(ROW).map(row => row.get(TRAINING).text())).toEqual([
      'Attributs non synchronisés',
      'Compétence absente du SDE',
      'Points de compétence inconnus',
      'Niveau actif sous le niveau entraîné',
      'Niveau maximal atteint',
    ])
  })

  it('shows a known gain of zero as zero and not as unknown', () => {
    const wrapper = mountTable([line({ hoursSavedPerMonth: 0, monthlyGain: 0, gainPerTrainingDay: 0 })])

    const row = wrapper.get(ROW)
    expect(row.get(MONTHLY).text()).toBe('0')
    expect(row.get(PER_DAY).text()).toBe('0')
    expect(row.find(UNKNOWN).exists()).toBe(false)
  })

  it('names a skill without name by its identifier and truncates a long name with its full name as title', () => {
    const wrapper = mountTable([line({ skillId: 9999, skillName: null }), line({ skillName: LONG_SKILL_NAME })])

    const names = wrapper.findAll(NAME)
    expect(names[0].text()).toContain('Compétence #9999')
    expect(names[1].find('[title]').attributes('title')).toBe(LONG_SKILL_NAME)
    expect(names[1].find('[title]').classes()).toContain('truncate')
  })

  it('renders no row for no line', () => {
    expect(mountTable([]).findAll(ROW)).toHaveLength(0)
  })
})
