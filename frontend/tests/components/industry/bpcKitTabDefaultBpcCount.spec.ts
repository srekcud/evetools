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

function vagabondKitWithRunsPerBpcWithoutDecryptor(runsPerBpc: number): BpcKit {
  const kit = structuredClone(VAGABOND_BPC_KIT)
  kit.inventions[0].decryptorOptions[0].runs = runsPerBpc
  return kit
}

function vagabondKitWithAugmentationDecryptorAsBest(): BpcKit {
  const kit = structuredClone(VAGABOND_BPC_KIT)
  kit.summary.bestDecryptorTypeId = AUGMENTATION_DECRYPTOR_TYPE_ID
  return kit
}

const VAGABOND_COMPONENT_BPC_KIT: BpcKit = {
  id: VAGABOND_PROJECT_ID,
  isT2: false,
  inventions: [],
  summary: { totalInventionCost: 0, bestDecryptorTypeId: null, totalBpcKitCost: 0 },
}

function serveBpcKit(kit: BpcKit): void {
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (isBpcKitRequest(path)) return structuredClone(kit)
    return null
  })
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

function decryptorRow(wrapper: VueWrapper, decryptorName: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(decryptorName))
  expect(row, `decryptor row ${decryptorName}`).toBeDefined()
  return row!
}

async function selectDecryptor(wrapper: VueWrapper, decryptorName: string): Promise<void> {
  await decryptorRow(wrapper, decryptorName).trigger('click')
  await settle()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.mocked(apiRequest).mockReset()
  serveBpcKit(VAGABOND_BPC_KIT)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('BpcKitTab default desired BPC count', () => {
  it('proposes and requests 3 BPCs for a 30 runs project with 10 runs per BPC', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    expect(requestedDesiredBpcCounts()).toEqual([null, 3])
    expect(desiredBpcCountInput(wrapper).element.value).toBe('3')
  })

  it('rounds the desired BPC count up to cover every run of a 31 runs project with 10 runs per BPC', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(31))

    expect(requestedDesiredBpcCounts()).toEqual([null, 4])
    expect(desiredBpcCountInput(wrapper).element.value).toBe('4')
  })

  it('proposes a single BPC when one BPC holds more runs than the project', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(7))

    expect(desiredBpcCountInput(wrapper).element.value).toBe('1')
    expect(requestedDesiredBpcCounts()).toEqual([null])
  })

  it('proposes one BPC per run when each BPC holds a single run', async () => {
    serveBpcKit(vagabondKitWithRunsPerBpcWithoutDecryptor(1))

    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    expect(desiredBpcCountInput(wrapper).element.value).toBe('30')
    expect(requestedDesiredBpcCounts()).toEqual([null, 30])
  })

  it('proposes no desired BPC count when the runs per BPC are zero', async () => {
    serveBpcKit(vagabondKitWithRunsPerBpcWithoutDecryptor(0))

    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    expect(desiredBpcCountInput(wrapper).element.value).toBe('')
    expect(requestedDesiredBpcCounts()).toEqual([null])
  })

  it('recomputes the desired BPC count from the runs per BPC of the selected decryptor', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await selectDecryptor(wrapper, 'Augmentation Decryptor')

    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 2])
    expect(desiredBpcCountInput(wrapper).element.value).toBe('2')
    expect(decryptorRow(wrapper, 'Augmentation Decryptor').classes()).toContain('bg-cyan-500/10')
  })

  it('proposes the desired BPC count from the runs per BPC of the best decryptor', async () => {
    serveBpcKit(vagabondKitWithAugmentationDecryptorAsBest())

    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    expect(desiredBpcCountInput(wrapper).element.value).toBe('2')
    expect(requestedDesiredBpcCounts()).toEqual([null, 2])
  })

  it('recomputes the desired BPC count when the runs of the project change', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    useProjectsStore().currentProject = vagabondProjectOf(52)
    await settle()

    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 6])
    expect(desiredBpcCountInput(wrapper).element.value).toBe('6')
  })

  it('keeps the desired BPC count typed by the user when the runs of the project change', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await desiredBpcCountInput(wrapper).setValue('5')
    await settle()
    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 5])

    useProjectsStore().currentProject = vagabondProjectOf(52)
    await settle()

    expect(desiredBpcCountInput(wrapper).element.value).toBe('5')
    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 5])
  })

  it('requests the BPC kit without desired BPC count when the user types a single BPC', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))
    await desiredBpcCountInput(wrapper).setValue('5')
    await settle()

    await desiredBpcCountInput(wrapper).setValue('1')
    await settle()

    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 5, null])
  })

  it('requests nothing when the user types a zero or negative desired BPC count', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await desiredBpcCountInput(wrapper).setValue('0')
    await settle()
    await desiredBpcCountInput(wrapper).setValue('-2')
    await settle()

    expect(requestedDesiredBpcCounts()).toEqual([null, 3])
  })

  it('keeps the desired BPC count cleared by the user when the runs of the project change', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await desiredBpcCountInput(wrapper).setValue('')
    await settle()
    useProjectsStore().currentProject = vagabondProjectOf(52)
    await settle()

    expect(desiredBpcCountInput(wrapper).element.value).toBe('')
    expect(requestedDesiredBpcCounts()).toEqual([null, 3])
  })

  it('requests the BPC kit once with the last desired BPC count typed within 500 ms', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    await desiredBpcCountInput(wrapper).setValue('4')
    await desiredBpcCountInput(wrapper).setValue('5')
    vi.advanceTimersByTime(499)
    expect(requestedDesiredBpcCounts()).toEqual([null, 3])

    vi.advanceTimersByTime(1)
    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 5])
  })

  it('requests nothing when the user types back the desired BPC count already requested', async () => {
    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))
    await desiredBpcCountInput(wrapper).setValue('5')
    await settle()

    await desiredBpcCountInput(wrapper).setValue('3')
    await desiredBpcCountInput(wrapper).setValue('5')
    await settle()

    expect(requestedDesiredBpcCounts()).toEqual([null, 3, 5])
  })

  it('proposes no desired BPC count while the runs of the project are unknown', async () => {
    const wrapper = await mountTheVagabondKit(null)

    expect(desiredBpcCountInput(wrapper).element.value).toBe('')
    expect(requestedDesiredBpcCounts()).toEqual([null])
  })

  it('proposes the desired BPC count once the runs of the project become known', async () => {
    const wrapper = await mountTheVagabondKit(null)

    useProjectsStore().currentProject = vagabondProjectOf(30)
    await settle()

    expect(desiredBpcCountInput(wrapper).element.value).toBe('3')
    expect(requestedDesiredBpcCounts()).toEqual([null, 3])
  })

  it('hides the desired BPC count of a kit without invention', async () => {
    serveBpcKit(VAGABOND_COMPONENT_BPC_KIT)

    const wrapper = await mountTheVagabondKit(vagabondProjectOf(30))

    expect(wrapper.findAll('label').some(label => label.text().includes(desiredBpcsLabel))).toBe(false)
    expect(wrapper.find('input[type="number"]').exists()).toBe(false)
    expect(requestedDesiredBpcCounts()).toEqual([null])
  })
})
