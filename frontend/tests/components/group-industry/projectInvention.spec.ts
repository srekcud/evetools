import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectInvention from '@/components/group-industry/ProjectInvention.vue'
import { apiRequest } from '@/services/api'
import type { ProjectInvention as ProjectInventionPayload, ProjectInventionProduct } from '@/stores/group-industry/types'
import type { ProfitMarginInventionOption } from '@/stores/industry/types'
import { useAuthStore } from '@/stores/auth'
import type { Character } from '@/types'
import { deferred } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const INVENTION_PATH = `/industry/projects/${PROJECT_ID}/invention`
const MAIN_CHARACTER_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8f90'
const ALT_CHARACTER_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8faa'
const HAMMERHEAD_II_TYPE_ID = 2185
const BLANK = /[\u00a0\u202f]/g

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function character(id: string, name: string, isMain: boolean): Character {
  return {
    id,
    eveCharacterId: 2_112_000_000 + name.length,
    name,
    corporationId: 98_000_007,
    corporationName: 'Sabre Doctrine Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

function option(overrides: Partial<ProfitMarginInventionOption>): ProfitMarginInventionOption {
  return {
    decryptorTypeId: null,
    decryptorName: 'No decryptor',
    me: 2,
    te: 4,
    runs: 10,
    probability: 0.34,
    inventionCost: 1_245_000,
    totalProductionCost: 55_000_000,
    bestMargin: null,
    expectedAttempts: 14.7,
    manufacturingJobs: 5,
    decryptorCost: 0,
    costPerUnit: 1_100_000,
    costPerUnitDelta: 0,
    ...overrides,
  }
}

const NO_DECRYPTOR = option({})
const ACCELERANT = option({
  decryptorTypeId: 34_201,
  decryptorName: 'Accelerant Decryptor',
  probability: 0.51,
  runs: 11,
  expectedAttempts: 8.9,
  manufacturingJobs: 5,
  decryptorCost: 900_000,
  inventionCost: 980_000,
  totalProductionCost: 52_500_000,
  costPerUnit: 1_050_000,
  costPerUnitDelta: -50_000,
})
const AUGMENTATION = option({
  decryptorTypeId: 34_207,
  decryptorName: 'Augmentation Decryptor',
  probability: 0.22,
  runs: 18,
  expectedAttempts: 14.2,
  manufacturingJobs: 3,
  decryptorCost: 1_500_000,
  inventionCost: 2_400_000,
  totalProductionCost: 58_000_000,
  costPerUnit: 1_160_000,
  costPerUnitDelta: 60_000,
})

function product(overrides: Partial<ProjectInventionProduct> = {}): ProjectInventionProduct {
  return {
    typeId: HAMMERHEAD_II_TYPE_ID,
    typeName: 'Hammerhead II',
    runs: 50,
    me: 2,
    te: 4,
    invention: {
      baseProbability: 0.34,
      unknownReason: null,
      datacores: ['Datacore - Electronic Engineering', 'Datacore - Graviton Physics'],
      datacoresCostPerAttempt: 74_000,
      inventor: null,
      options: [NO_DECRYPTOR, ACCELERANT, AUGMENTATION],
    },
    ...overrides,
  }
}

function payload(products: ProjectInventionProduct[]): ProjectInventionPayload {
  return { id: PROJECT_ID, products }
}

async function mountInvention(answer: unknown, characters: Character[] = [character(MAIN_CHARACTER_ID, 'Pilot Prime', true), character(ALT_CHARACTER_ID, 'Pilot Alt', false)]) {
  vi.mocked(apiRequest).mockResolvedValue(answer)
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.user = { id: 'user-1', authStatus: 'authenticated', mainCharacter: characters.find(entry => entry.isMain) ?? null, characters, corporationId: 98_000_007, corporationName: 'Sabre Doctrine Corp' }
  const wrapper = mount(ProjectInvention, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function inventionCalls(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => String(endpoint)).filter(endpoint => endpoint.startsWith(INVENTION_PATH))
}

function rowText(wrapper: VueWrapper, decryptorTypeId: number | 'none'): string {
  const row = wrapper.find(`[data-testid="invention-option-${HAMMERHEAD_II_TYPE_ID}-${decryptorTypeId}"]`)
  return row.exists() ? row.text().replace(BLANK, ' ') : 'absent'
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectInvention', () => {
  it('reads the comparison with the main character as inventor and shows one table per invented product with the units, ME and TE of the project', async () => {
    const wrapper = await mountInvention(payload([product()]))

    const card = wrapper.find(`[data-testid="invention-product-${HAMMERHEAD_II_TYPE_ID}"]`)
    expect({
      calls: inventionCalls(),
      cardShowsTheProduct: card.text().includes('Hammerhead II'),
      cardShowsTheUnits: card.find('[data-testid="invention-product-units"]').text(),
      cardShowsMeAndTe: card.find('[data-testid="invention-product-efficiency"]').text(),
      optionRows: wrapper.findAll('[data-testid^="invention-option-"]').length,
      emptyStateShown: wrapper.find('[data-testid="invention-empty"]').exists(),
    }).toEqual({
      calls: [`${INVENTION_PATH}?inventorCharacterId=${MAIN_CHARACTER_ID}`],
      cardShowsTheProduct: true,
      cardShowsTheUnits: expect.stringContaining('50'),
      cardShowsMeAndTe: expect.stringMatching(/ME\s*2.*TE\s*4/),
      optionRows: 3,
      emptyStateShown: false,
    })
  })

  it('shows the probability, the runs per copy, the attempts, the jobs, the invention cost, the cost per unit and the delta of each decryptor', async () => {
    const wrapper = await mountInvention(payload([product()]))

    expect({
      accelerant: rowText(wrapper, 34_201),
      augmentation: rowText(wrapper, 34_207),
    }).toEqual({
      accelerant: expect.stringMatching(/Accelerant Decryptor.*51(\.0)?%.*11.*8\.9.*5.*/),
      augmentation: expect.stringMatching(/Augmentation Decryptor.*22(\.0)?%.*18.*14\.2.*3.*/),
    })
    expect(rowText(wrapper, 34_201)).toContain('-')
    expect(rowText(wrapper, 34_207)).toContain('+')
  })

  it('marks the decryptor with the lowest known cost per unit as the cheapest and no other', async () => {
    const wrapper = await mountInvention(payload([product()]))

    const cheapestRows = wrapper.findAll('[data-testid="invention-cheapest"]')
    expect({
      count: cheapestRows.length,
      inTheAccelerantRow: wrapper.find(`[data-testid="invention-option-${HAMMERHEAD_II_TYPE_ID}-34201"] [data-testid="invention-cheapest"]`).exists(),
    }).toEqual({ count: 1, inTheAccelerantRow: true })
  })

  it('shows unknown, never zero, for a probability and costs that are unknown and explains why, with no cheapest marker', async () => {
    const unknownOption = option({ probability: null, expectedAttempts: null, inventionCost: null, totalProductionCost: null, costPerUnit: null, costPerUnitDelta: null })
    const wrapper = await mountInvention(payload([product({
      invention: {
        baseProbability: 0.34,
        unknownReason: 'missing_inventor_skills',
        datacores: ['Datacore - Electronic Engineering'],
        datacoresCostPerAttempt: null,
        inventor: { characterId: MAIN_CHARACTER_ID, characterName: 'Pilot Prime', skillsKnown: false },
        options: [unknownOption],
      },
    })]))

    const unknownLabel = en.industry.inventionUnknown.label
    const text = rowText(wrapper, 'none')
    expect({
      unknownCount: text.split(unknownLabel).length - 1 >= 3,
      noZeroPercent: !text.includes('0.0%'),
      reasonShown: wrapper.text().includes(en.industry.inventionUnknown.missing_inventor_skills),
      cheapestMarkers: wrapper.findAll('[data-testid="invention-cheapest"]').length,
    }).toEqual({ unknownCount: true, noZeroPercent: true, reasonShown: true, cheapestMarkers: 0 })
  })

  it('reads the comparison again with the chosen character when the inventor changes, and with no inventor when without skills is chosen', async () => {
    const wrapper = await mountInvention(payload([product()]))
    const select = wrapper.find('[data-testid="invention-inventor-select"]')

    await select.setValue(ALT_CHARACTER_ID)
    await flushPromises()
    await select.setValue('')
    await flushPromises()

    expect(inventionCalls()).toEqual([
      `${INVENTION_PATH}?inventorCharacterId=${MAIN_CHARACTER_ID}`,
      `${INVENTION_PATH}?inventorCharacterId=${ALT_CHARACTER_ID}`,
      INVENTION_PATH,
    ])
  })

  it('offers the characters of the connected user and the without skills choice in the inventor selector', async () => {
    const wrapper = await mountInvention(payload([product()]))

    const options = wrapper.findAll('[data-testid="invention-inventor-select"] option')
    expect(options.map(entry => [entry.attributes('value'), entry.text()])).toEqual([
      ['', en.industry.margins.withoutSkills],
      [MAIN_CHARACTER_ID, 'Pilot Prime'],
      [ALT_CHARACTER_ID, 'Pilot Alt'],
    ])
  })

  it('shows an explanatory sentence and no table for a project without an invented product, and never a made up value', async () => {
    const wrapper = await mountInvention(payload([]))

    expect({
      sentence: wrapper.find('[data-testid="invention-empty"]').text(),
      tables: wrapper.findAll('table').length,
    }).toEqual({ sentence: en.groupIndustry.invention.empty, tables: 0 })
  })

  it('shows the error of the request in an alert and no stale table', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Project not found'))
    setActivePinia(createPinia())
    const wrapper = mount(ProjectInvention, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect({
      alert: wrapper.find('[role="alert"]').text(),
      tables: wrapper.findAll('table').length,
      emptySentence: wrapper.find('[data-testid="invention-empty"]').exists(),
    }).toEqual({ alert: expect.stringContaining('Project not found'), tables: 0, emptySentence: false })
  })

  it('shows a loading state while the comparison is being read and no empty sentence', async () => {
    const pending = deferred<ProjectInventionPayload>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    setActivePinia(createPinia())
    const wrapper = mount(ProjectInvention, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect({
      loading: wrapper.find('[data-testid="invention-loading"]').exists(),
      emptySentence: wrapper.find('[data-testid="invention-empty"]').exists(),
    }).toEqual({ loading: true, emptySentence: false })
  })

  it('has the labels of the tab and of the empty state in English and in French', () => {
    const invention = (messages: typeof en | typeof fr): Record<string, unknown> => messages.groupIndustry.invention
    expect({
      english: Object.keys(invention(en)).includes('empty'),
      french: Object.keys(invention(fr)).includes('empty'),
    }).toEqual({ english: true, french: true })
  })
})
