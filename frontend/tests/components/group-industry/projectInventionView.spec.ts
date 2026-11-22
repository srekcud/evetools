import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectInventionView from '@/components/group-industry/ProjectInventionView.vue'
import { apiRequest } from '@/services/api'
import type { BomItem, JobMatch, ProjectInvention } from '@/stores/group-industry/types'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { bomLine, jobMatch } from './companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-hammerhead'
const NOW = Date.parse('2026-11-22T12:00:00+00:00')
const THREE_HOURS_MS = 3 * 60 * 60 * 1000
const PRODUCT_TYPE_ID = 2_205
const INVENTION_LINE_ID = 'line-invention'
const ATTEMPTS = 59
const EXPECTED_COPIES = 20.06

const TITLE: Record<TestLocale, string> = { fr: 'Comprendre l\'invention', en: 'Understand invention' }
const EXPECTED_BPC: Record<TestLocale, string> = { fr: '≈ 20,1 BPC T2', en: '≈ 20.1 T2 BPCs' }
const OBTAINED_BPC: Record<TestLocale, string> = { fr: '21 BPC T2', en: '21 T2 BPCs' }
const RESULT_CAPTION: Record<TestLocale, string> = { fr: '21 réussies · 38 ratées', en: '21 succeeded · 38 failed' }
const IDLE_CAPTION: Record<TestLocale, string> = {
  fr: '1 point = 1 tentative · ≈ 20,1 réussies attendues',
  en: '1 dot = 1 attempt · ≈ 20.1 successes expected',
}
const RUNNING_CAPTION: Record<TestLocale, string> = {
  fr: 'en cours : le résultat tombe à la fin du job',
  en: 'running: the result comes when the job ends',
}
const INVENTION_ARROW: Record<TestLocale, string> = {
  fr: 'invention · 59 tentatives · 34 %',
  en: 'invention · 59 attempts · 34 %',
}

function inventionLine(overrides: Partial<BomItem> = {}): BomItem {
  return bomLine({
    id: INVENTION_LINE_ID,
    typeId: PRODUCT_TYPE_ID,
    typeName: 'Hammerhead II',
    isJob: true,
    jobGroup: 'blueprint',
    activityType: 'invention',
    runs: ATTEMPTS,
    expectedBlueprintCopies: EXPECTED_COPIES,
    ...overrides,
  })
}

function manufacturingLine(overrides: Partial<BomItem> = {}): BomItem {
  return bomLine({
    id: 'line-manufacturing',
    typeId: PRODUCT_TYPE_ID,
    typeName: 'Hammerhead II',
    isJob: true,
    jobGroup: 'final',
    activityType: 'manufacturing',
    requiredQuantity: 200,
    runs: 200,
    ...overrides,
  })
}

function deliveredJob(id: string, esiJobId: number, runs: number, successfulRuns: number | null): JobMatch {
  return jobMatch({ id, bomLineId: INVENTION_LINE_ID, status: 'delivered', esiJobId, runs, successfulRuns })
}

function runningJob(id: string, esiJobId: number, runs: number): JobMatch {
  return jobMatch({
    id,
    bomLineId: INVENTION_LINE_ID,
    status: 'active',
    esiJobId,
    runs,
    endDate: new Date(NOW + THREE_HOURS_MS).toISOString(),
  })
}

function inventionPayload(): ProjectInvention {
  return {
    id: PROJECT_ID,
    products: [{
      typeId: PRODUCT_TYPE_ID,
      typeName: 'Hammerhead II',
      runs: 200,
      me: 2,
      te: 4,
      selectedDecryptorTypeId: null,
      invention: {
        baseProbability: 0.34,
        unknownReason: null,
        datacores: ['Datacore - Mechanical Engineering', 'Datacore - Gallentean Starship Engineering'],
        datacoresCostPerAttempt: 1000,
        inventor: null,
        options: [{ decryptorTypeId: null, decryptorName: null, probability: 0.34, runs: 10, me: 2, te: 4 }],
      },
    }],
  } as unknown as ProjectInvention
}

async function mountView(bomItems: BomItem[], locale: TestLocale = 'fr'): Promise<VueWrapper> {
  const wrapper = mount(ProjectInventionView, {
    props: { projectId: PROJECT_ID, bomItems, now: NOW },
    global: { plugins: [createPinia(), createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function dotStates(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="invention-view-dot"]').map(dot => dot.attributes('data-state') ?? 'missing')
}

function countOf(states: string[], state: string): number {
  return states.filter(candidate => candidate === state).length
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim() : 'absent'
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(inventionPayload())
})

describe('Project invention view', () => {
  it('shows nothing on a project without an invention line', async () => {
    const wrapper = await mountView([manufacturingLine()])

    expect(wrapper.find('[data-testid="invention-view"]').exists()).toBe(false)
  })

  it.each(TEST_LOCALES)('titles the view and names the invented product in %s', async locale => {
    const wrapper = await mountView([inventionLine(), manufacturingLine()], locale)

    expect(textOf(wrapper, 'invention-view')).toContain(TITLE[locale])
    expect(textOf(wrapper, `invention-view-product-${PRODUCT_TYPE_ID}`)).toContain('Hammerhead II')
  })

  it.each(TEST_LOCALES)('draws one empty square per attempt before any job in %s', async locale => {
    const wrapper = await mountView([inventionLine(), manufacturingLine()], locale)

    const states = dotStates(wrapper)
    expect(states).toHaveLength(ATTEMPTS)
    expect(countOf(states, 'empty')).toBe(ATTEMPTS)
    expect(textOf(wrapper, 'invention-view-dots-caption')).toBe(IDLE_CAPTION[locale])
    expect(textOf(wrapper, 'invention-view-stage-bpc')).toContain(EXPECTED_BPC[locale])
  })

  it('fills the squares of a running job and leaves the others empty', async () => {
    const wrapper = await mountView([inventionLine({ jobMatches: [runningJob('job-a', 1, 20)] }), manufacturingLine()])

    const states = dotStates(wrapper)
    expect(countOf(states, 'busy')).toBe(20)
    expect(countOf(states, 'empty')).toBe(ATTEMPTS - 20)
    expect(textOf(wrapper, 'invention-view-dots-caption')).toBe(RUNNING_CAPTION.fr)
  })

  it.each(TEST_LOCALES)('colours the squares green or grey from the real result in %s', async locale => {
    const wrapper = await mountView([inventionLine({ jobMatches: [deliveredJob('job-a', 1, ATTEMPTS, 21)] }), manufacturingLine()], locale)

    const states = dotStates(wrapper)
    expect(countOf(states, 'win')).toBe(21)
    expect(countOf(states, 'lose')).toBe(38)
    expect(textOf(wrapper, 'invention-view-dots-caption')).toBe(RESULT_CAPTION[locale])
    expect(textOf(wrapper, 'invention-view-stage-bpc')).toContain(OBTAINED_BPC[locale])
  })

  it('adds the results of several finished jobs', async () => {
    const jobs = [deliveredJob('job-a', 1, 20, 7), deliveredJob('job-b', 2, 39, 14)]
    const wrapper = await mountView([inventionLine({ jobMatches: jobs }), manufacturingLine()])

    const states = dotStates(wrapper)
    expect(countOf(states, 'win')).toBe(21)
    expect(countOf(states, 'lose')).toBe(38)
  })

  it('counts a finished job without a successful run as a real zero', async () => {
    const wrapper = await mountView([inventionLine({ jobMatches: [deliveredJob('job-a', 1, ATTEMPTS, 0)] }), manufacturingLine()])

    const states = dotStates(wrapper)
    expect(countOf(states, 'win')).toBe(0)
    expect(countOf(states, 'lose')).toBe(ATTEMPTS)
    expect(textOf(wrapper, 'invention-view-stage-bpc')).toContain('0 BPC T2')
  })

  it('marks the squares of a finished job whose result is unknown without inventing a split', async () => {
    const wrapper = await mountView([inventionLine({ jobMatches: [deliveredJob('job-a', 1, ATTEMPTS, null)] }), manufacturingLine()])

    const states = dotStates(wrapper)
    expect(countOf(states, 'unknown')).toBe(ATTEMPTS)
    expect(countOf(states, 'win')).toBe(0)
    expect(textOf(wrapper, 'invention-view-stage-bpc')).toContain(EXPECTED_BPC.fr)
  })

  it('gives no total while one of the jobs is still running', async () => {
    const jobs = [deliveredJob('job-a', 1, 20, 7), runningJob('job-b', 2, 39)]
    const wrapper = await mountView([inventionLine({ jobMatches: jobs }), manufacturingLine()])

    const states = dotStates(wrapper)
    expect(countOf(states, 'win')).toBe(7)
    expect(countOf(states, 'lose')).toBe(13)
    expect(countOf(states, 'busy')).toBe(39)
    expect(textOf(wrapper, 'invention-view-dots-caption')).toBe(RUNNING_CAPTION.fr)
  })

  it.each(TEST_LOCALES)('shows the plan chance on the invention arrow in %s', async locale => {
    const wrapper = await mountView([inventionLine(), manufacturingLine()], locale)

    expect(textOf(wrapper, 'invention-view-arrow-invention')).toContain(INVENTION_ARROW[locale])
  })

  it('names the datacores and the blueprint copy yield read from the invention comparison', async () => {
    const wrapper = await mountView([inventionLine(), manufacturingLine()])

    expect(textOf(wrapper, 'invention-view-stage-datacores')).toContain('Datacore - Mechanical Engineering + Datacore - Gallentean Starship Engineering')
    expect(textOf(wrapper, 'invention-view-stage-bpc')).toContain('10 runs · ME 2 / TE 4')
  })

  it('ends on the manufacturing of the invented product', async () => {
    const wrapper = await mountView([inventionLine(), manufacturingLine()])

    const product = textOf(wrapper, 'invention-view-stage-product')
    expect(product).toContain('200')
    expect(product).toContain('Hammerhead II')
  })

  it.each(TEST_LOCALES)('explains consumption, randomness and decryptors in %s', async locale => {
    const wrapper = await mountView([inventionLine(), manufacturingLine()], locale)

    const notes = textOf(wrapper, 'invention-view-notes')
    expect(notes).toContain(locale === 'fr' ? 'réussie ou ratée' : 'successful or not')
    expect(notes).toContain(locale === 'fr' ? 'nombre de BPC' : 'number of BPCs')
    expect(notes).toContain(locale === 'fr' ? 'décrypteur' : 'decryptor')
  })

  it('keeps the squares and reports the failure when the invention comparison cannot be read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('boom'))
    const wrapper = await mountView([inventionLine(), manufacturingLine()])

    expect(dotStates(wrapper)).toHaveLength(ATTEMPTS)
    expect(textOf(wrapper, 'invention-view-error')).toContain('boom')
  })
})
