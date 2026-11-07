import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectReactionSteps from '@/components/group-industry/ProjectReactionSteps.vue'
import { apiRequest } from '@/services/api'
import { useGroupReactionStepsStore } from '@/stores/group-industry/reactionSteps'
import type { ReactionStep } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import { messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  CAESARIUM_CADMIDE,
  CAESARIUM_CADMIDE_ID,
  FULLERIDES,
  FULLERIDES_ID,
  HYPERSYNAPTIC_FIBERS,
  HYPERSYNAPTIC_FIBERS_ID,
  reactionStep,
  THULIUM_HAFNITE,
  THULIUM_HAFNITE_ID,
} from '../../reactionStepFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-fullerides-batch'
const LONG_TYPE_NAME = 'Caesarium Cadmide Reaction Formula for the Ishtar Heavy Assault Cruiser Fleet Refit Programme'
const DASH = /^[-–—]+$/

function label(locale: TestLocale, key: string): string {
  return messageOf(locale, `groupIndustry.reactionSteps.${key}`)
}

async function mountSteps(steps: ReactionStep[], locale: TestLocale = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue({ id: PROJECT_ID, steps })
  setActivePinia(createPinia())
  useGroupReactionStepsStore().steps = steps
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProjectReactionSteps, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

type FoundElement = Omit<DOMWrapper<Element>, 'exists'>

function rowOf(wrapper: VueWrapper, typeId: number): FoundElement {
  return wrapper.get(`[data-testid="reaction-step-row"][data-type-id="${typeId}"]`)
}

function textOf(element: Pick<DOMWrapper<Element>, 'text'>): string {
  return element.text().replace(/[\u00a0\u202f]/g, ' ')
}

function cellText(wrapper: VueWrapper, typeId: number, testId: string): string {
  return textOf(rowOf(wrapper, typeId).get(`[data-testid="${testId}"]`))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectReactionSteps', () => {
  it('lists one row per step in the given order with its formula quantities per run and the fuel blocks marked', async () => {
    const wrapper = await mountSteps([FULLERIDES, HYPERSYNAPTIC_FIBERS, CAESARIUM_CADMIDE])

    const rows = wrapper.findAll('[data-testid="reaction-step-row"]').map(row => Number(row.attributes('data-type-id')))
    const fulleridesFormula = cellText(wrapper, FULLERIDES_ID, 'reaction-step-formula')
    const fibersFormula = cellText(wrapper, HYPERSYNAPTIC_FIBERS_ID, 'reaction-step-formula')
    const fuelMarks = (typeId: number) => rowOf(wrapper, typeId)
      .findAll('[data-testid="reaction-step-input"]')
      .map(input => input.attributes('data-fuel-block'))

    expect(rows).toEqual([FULLERIDES_ID, HYPERSYNAPTIC_FIBERS_ID, CAESARIUM_CADMIDE_ID])
    expect(rowOf(wrapper, FULLERIDES_ID).text()).toContain('Fullerides')
    expect(fulleridesFormula).toContain('Carbon Polymers ×100')
    expect(fulleridesFormula).toContain('Platinum Technite ×100')
    expect(fulleridesFormula).toContain('Nitrogen Fuel Block ×5')
    expect(fibersFormula).toContain('Silicon Dioxide ×60')
    expect(fibersFormula).toContain('Hydrogen Fuel Block ×3')
    expect(fuelMarks(FULLERIDES_ID)).toEqual(['false', 'false', 'true'])
    expect(fuelMarks(HYPERSYNAPTIC_FIBERS_ID)).toEqual(['false', 'true'])
  })

  it.each([
    { locale: 'en' as const, fulleridesProduced: '3,000', fulleridesSurplus: '2,509', fibersProduced: '1,000', share: /83\.6\s?%/ },
    { locale: 'fr' as const, fulleridesProduced: '3 000', fulleridesSurplus: '2 509', fibersProduced: '1 000', share: /83,6\s?%/ },
  ])('shows the need, the runs with their base duration in hours, the produced quantity and the surplus with its share of it in $locale', async ({ locale, fulleridesProduced, fulleridesSurplus, fibersProduced, share }) => {
    const wrapper = await mountSteps([FULLERIDES, HYPERSYNAPTIC_FIBERS], locale)

    expect(cellText(wrapper, FULLERIDES_ID, 'reaction-step-needed')).toBe('491')
    expect(cellText(wrapper, FULLERIDES_ID, 'reaction-step-runs-duration')).toMatch(/\b1\b.*\b3\s?h\b/)
    expect(cellText(wrapper, FULLERIDES_ID, 'reaction-step-produced')).toBe(fulleridesProduced)
    expect(cellText(wrapper, FULLERIDES_ID, 'reaction-step-surplus')).toContain(fulleridesSurplus)
    expect(cellText(wrapper, FULLERIDES_ID, 'reaction-step-surplus')).toMatch(share)
    expect(cellText(wrapper, HYPERSYNAPTIC_FIBERS_ID, 'reaction-step-runs-duration')).toMatch(/\b5\b.*\b15\s?h\b/)
    expect(cellText(wrapper, HYPERSYNAPTIC_FIBERS_ID, 'reaction-step-produced')).toBe(fibersProduced)
    expect(cellText(wrapper, HYPERSYNAPTIC_FIBERS_ID, 'reaction-step-surplus')).toMatch(/(^|\D)0\s?%/)
    expect(textOf(wrapper.get('[data-testid="reaction-steps"]'))).toContain(label(locale, 'title'))
    expect(textOf(wrapper.get('[data-testid="reaction-steps"]'))).toContain(label(locale, 'baseDurationHint'))
    expect(textOf(wrapper.get('[data-testid="reaction-steps"]'))).toContain(label(locale, 'costsHint'))
  })

  it.each(TEST_LOCALES)('warns in %s that the whole run produces more than the need when a step has a surplus, and shows no warning when every surplus is zero', async locale => {
    const withSurplus = await mountSteps([FULLERIDES, HYPERSYNAPTIC_FIBERS], locale)
    const warningWithSurplus = withSurplus.find('[data-testid="reaction-steps-warning"]')
    const withoutSurplus = await mountSteps([HYPERSYNAPTIC_FIBERS, reactionStep({ typeId: 345_010, typeName: 'Ytterbium Silicate', surplusQuantity: 0, neededQuantity: 3_000 })], locale)

    expect(warningWithSurplus.exists()).toBe(true)
    expect(textOf(warningWithSurplus)).toContain(label(locale, 'warning'))
    expect(withoutSurplus.find('[data-testid="reaction-steps-warning"]').exists()).toBe(false)
  })

  it('shows a dash and the cause instead of 0 when the surplus was not recorded, and keeps the formula and the duration of the step', async () => {
    const wrapper = await mountSteps([CAESARIUM_CADMIDE])

    const surplus = rowOf(wrapper, CAESARIUM_CADMIDE_ID).get('[data-testid="reaction-step-surplus"]')
    const cause = rowOf(wrapper, CAESARIUM_CADMIDE_ID).get('[data-testid="reaction-step-surplus-unknown"]')

    expect(textOf(cause)).toContain(label('en', 'surplusUnknown'))
    expect(textOf(surplus).replace(label('en', 'surplusUnknown'), '').trim()).toMatch(DASH)
    expect(cellText(wrapper, CAESARIUM_CADMIDE_ID, 'reaction-step-needed')).toMatch(DASH)
    expect(cellText(wrapper, CAESARIUM_CADMIDE_ID, 'reaction-step-produced')).toBe('600')
    expect(cellText(wrapper, CAESARIUM_CADMIDE_ID, 'reaction-step-formula')).toContain('Cadmium ×40')
    expect(cellText(wrapper, CAESARIUM_CADMIDE_ID, 'reaction-step-runs-duration')).toMatch(/\b2\b.*\b2\s?h\b/)
    expect(wrapper.find('[data-testid="reaction-steps-warning"]').exists()).toBe(false)
  })

  it.each(TEST_LOCALES)('says in %s that the formula is missing from the SDE and shows no duration for a step without formula, while keeping its quantities', async locale => {
    const wrapper = await mountSteps([THULIUM_HAFNITE, FULLERIDES], locale)

    const row = rowOf(wrapper, THULIUM_HAFNITE_ID)

    expect(textOf(row.get('[data-testid="reaction-step-formula-unknown"]'))).toContain(label(locale, 'formulaUnknown'))
    expect(row.findAll('[data-testid="reaction-step-input"]')).toHaveLength(0)
    expect(cellText(wrapper, THULIUM_HAFNITE_ID, 'reaction-step-runs-duration')).not.toMatch(/\d\s?h\b/)
    expect(cellText(wrapper, THULIUM_HAFNITE_ID, 'reaction-step-needed')).toBe('280')
    expect(cellText(wrapper, THULIUM_HAFNITE_ID, 'reaction-step-produced')).toBe('400')
    expect(rowOf(wrapper, FULLERIDES_ID).find('[data-testid="reaction-step-formula-unknown"]').exists()).toBe(false)
  })

  it('truncates a very long type name and keeps its full text in the title attribute', async () => {
    const wrapper = await mountSteps([reactionStep({ typeId: 345_020, typeName: LONG_TYPE_NAME })])

    const name = rowOf(wrapper, 345_020).get(`[title="${LONG_TYPE_NAME}"]`)

    expect(name.text()).toContain(LONG_TYPE_NAME)
    expect(name.classes()).toContain('truncate')
  })

  it('renders nothing when the project has no reaction step', async () => {
    const wrapper = await mountSteps([])

    expect(wrapper.find('[data-testid="reaction-steps"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reaction-step-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reaction-steps-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reaction-steps-error"]').exists()).toBe(false)
  })

  it('shows the loading text while the first read is pending and the API error when the read fails', async () => {
    vi.mocked(apiRequest).mockReturnValue(deferred<unknown>().promise)
    setActivePinia(createPinia())
    const store = useGroupReactionStepsStore()
    store.loading = true
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const loadingWrapper = mount(ProjectReactionSteps, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    vi.mocked(apiRequest).mockRejectedValue(new Error('Reaction steps unavailable: project not found'))
    setActivePinia(createPinia())
    useGroupReactionStepsStore().error = 'Reaction steps unavailable: project not found'
    const errorWrapper = mount(ProjectReactionSteps, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(textOf(loadingWrapper.get('[data-testid="reaction-steps-loading"]'))).toContain(label('en', 'loading'))
    expect(textOf(errorWrapper.get('[data-testid="reaction-steps-error"]'))).toContain(label('en', 'loadError'))
    expect(textOf(errorWrapper.get('[data-testid="reaction-steps-error"]'))).toContain('Reaction steps unavailable: project not found')
  })
})
