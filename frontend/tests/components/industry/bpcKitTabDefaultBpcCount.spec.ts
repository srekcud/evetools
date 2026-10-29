import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BpcKitTab from '@/components/industry/BpcKitTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { BpcKit, IndustryProject } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const VAGABOND_PROJECT_ID = 'vagabond-project'
const VAGABOND_TYPE_ID = 11999
const AUGMENTATION_DECRYPTOR_TYPE_ID = 34207
const RUNS_PER_BPC_WITHOUT_DECRYPTOR = 10
const RUNS_PER_BPC_WITH_AUGMENTATION_DECRYPTOR = 19

const VAGABOND_BPC_KIT: BpcKit = {
  id: VAGABOND_PROJECT_ID,
  isT2: true,
  inventions: [
    {
      productTypeId: VAGABOND_TYPE_ID,
      productName: 'Vagabond',
      baseProbability: 0.32,
      unknownReason: null,
      desiredSuccesses: 1,
      datacores: [
        { typeId: 20424, typeName: 'Datacore - Mechanical Engineering', quantity: 6, unitPrice: 48_000, totalPrice: 288_000 },
        { typeId: 20172, typeName: 'Datacore - Minmatar Starship Engineering', quantity: 7, unitPrice: 61_000, totalPrice: 427_000 },
      ],
      decryptorOptions: [
        {
          decryptorTypeId: null,
          decryptorName: 'No Decryptor',
          me: 2,
          te: 4,
          runs: RUNS_PER_BPC_WITHOUT_DECRYPTOR,
          probability: 0.32,
          costPerAttempt: 760_000,
          expectedAttempts: 4,
          totalCost: 3_040_000,
          costBreakdown: { datacores: 715_000, decryptor: 0, copyCost: 25_000, inventionInstall: 20_000 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
        {
          decryptorTypeId: AUGMENTATION_DECRYPTOR_TYPE_ID,
          decryptorName: 'Augmentation Decryptor',
          me: 0,
          te: 2,
          runs: RUNS_PER_BPC_WITH_AUGMENTATION_DECRYPTOR,
          probability: 0.192,
          costPerAttempt: 1_135_000,
          expectedAttempts: 6,
          totalCost: 6_810_000,
          costBreakdown: { datacores: 715_000, decryptor: 375_000, copyCost: 25_000, inventionInstall: 20_000 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
      ],
    },
  ],
  summary: { totalInventionCost: 3_040_000, bestDecryptorTypeId: null, totalBpcKitCost: 3_040_000 },
}

const desiredBpcsLabel = en.industry.bpcKitTab.desiredBpcs

function vagabondProjectOf(runs: number): IndustryProject {
  return {
    id: VAGABOND_PROJECT_ID,
    productTypeName: 'Vagabond',
    productTypeId: VAGABOND_TYPE_ID,
    displayName: null,
    runs,
    meLevel: 2,
    teLevel: 4,
    maxJobDurationDays: 3,
    status: 'active',
    personalUse: false,
    isT2: true,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-20T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
  }
}

function isBpcKitRequest(path: string): boolean {
  return path.split('?')[0] === `/industry/projects/${VAGABOND_PROJECT_ID}/bpc-kit`
}

function requestedDesiredBpcCounts(): (number | null)[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([path]) => path)
    .filter(isBpcKitRequest)
    .map(path => {
      const desiredBpcCount = new URLSearchParams(path.split('?')[1] ?? '').get('desired_bpc_count')
      return desiredBpcCount === null ? null : Number(desiredBpcCount)
    })
}

function lastRequestedDesiredBpcCount(): number | null | undefined {
  const counts = requestedDesiredBpcCounts()
  return counts[counts.length - 1]
}

async function settle(): Promise<void> {
  for (let round = 0; round < 3; round++) {
    await flushPromises()
    await vi.runAllTimersAsync()
  }
  await flushPromises()
}

async function mountTheVagabondKit(project: IndustryProject | null): Promise<VueWrapper> {
  useProjectsStore().currentProject = project
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BpcKitTab, {
    global: { plugins: [i18n] },
    props: { projectId: VAGABOND_PROJECT_ID },
  })
  await settle()
  return wrapper
}

function desiredBpcCountInput(wrapper: VueWrapper): DOMWrapper<HTMLInputElement> {
  const label = wrapper.findAll('label').find(candidate => candidate.text().includes(desiredBpcsLabel))
  expect(label, 'desired BPC count label').toBeDefined()
  const inputId = label!.attributes('for')
  const input = inputId === undefined
    ? label!.find<HTMLInputElement>('input')
    : wrapper.find<HTMLInputElement>(`[id="${inputId}"]`)
  expect(input.exists(), 'desired BPC count input').toBe(true)
  return input
}

async function selectDecryptor(wrapper: VueWrapper, decryptorName: string): Promise<void> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(decryptorName))
  expect(row, `decryptor row ${decryptorName}`).toBeDefined()
  await row!.trigger('click')
  await settle()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (isBpcKitRequest(path)) return structuredClone(VAGABOND_BPC_KIT)
    return null
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('BpcKitTab default desired BPC count', () => {
  it('proposes and requests 3 BPCs for a 30 runs project with 10 runs per BPC', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    expect(lastRequestedDesiredBpcCount()).toBe(3)
    expect(desiredBpcCountInput(wrapper).element.value).toBe('3')
  })

  it('rounds the desired BPC count up to cover every run of a 31 runs project with 10 runs per BPC', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(31))

    expect(lastRequestedDesiredBpcCount()).toBe(4)
    expect(desiredBpcCountInput(wrapper).element.value).toBe('4')
  })

  it('proposes a single BPC when one BPC holds more runs than the project', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(7))

    expect(desiredBpcCountInput(wrapper).element.value).toBe('1')
    expect(requestedDesiredBpcCounts()).toEqual([null])
  })

  it('recomputes the desired BPC count from the runs per BPC of the selected decryptor', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await selectDecryptor(wrapper, 'Augmentation Decryptor')

    expect(lastRequestedDesiredBpcCount()).toBe(2)
    expect(desiredBpcCountInput(wrapper).element.value).toBe('2')
  })

  it('recomputes the desired BPC count when the runs of the project change', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    useProjectsStore().currentProject = vagabondProjectOf(52)
    await settle()

    expect(lastRequestedDesiredBpcCount()).toBe(6)
    expect(desiredBpcCountInput(wrapper).element.value).toBe('6')
  })

  it('keeps the desired BPC count typed by the user when the runs of the project change', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await desiredBpcCountInput(wrapper).setValue('5')
    await settle()
    expect(lastRequestedDesiredBpcCount()).toBe(5)

    useProjectsStore().currentProject = vagabondProjectOf(52)
    await settle()

    expect(desiredBpcCountInput(wrapper).element.value).toBe('5')
    expect(lastRequestedDesiredBpcCount()).toBe(5)
  })

  it('proposes no desired BPC count while the runs of the project are unknown', async () => {
    const wrapper = await mountTheVagabondKit(null)

    expect(desiredBpcCountInput(wrapper).element.value).toBe('')
    expect(requestedDesiredBpcCounts()).toEqual([null])
  })
})
