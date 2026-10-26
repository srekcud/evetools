import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BpcKitTab from '@/components/industry/BpcKitTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { BpcKit } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE_PROJECT_ID = 'sabre-project'
const ISHTAR_PROJECT_ID = 'ishtar-project'
const ACCELERANT_DECRYPTOR_TYPE_ID = 34201
const ATTAINMENT_DECRYPTOR_TYPE_ID = 34203
const ELECTRONIC_ENGINEERING_DATACORE_TYPE_ID = 20418
const GALLENTEAN_STARSHIP_ENGINEERING_DATACORE_TYPE_ID = 20171
const ISHTAR_EXPECTED_ATTEMPTS = 2
const ISHTAR_ATTAINMENT_TOTAL_COST = 412_500
const ISHTAR_ATTAINMENT_ME = 1
const ISHTAR_ATTAINMENT_TE = 6

const SABRE_BPC_KIT: BpcKit = {
  id: SABRE_PROJECT_ID,
  isT2: true,
  inventions: [
    {
      productTypeId: 22456,
      productName: 'Sabre',
      baseProbability: 0.34,
      unknownReason: null,
      desiredSuccesses: 1,
      datacores: [
        { typeId: 20424, typeName: 'Datacore - Mechanical Engineering', quantity: 2, unitPrice: 100, totalPrice: 200 },
        { typeId: 20172, typeName: 'Datacore - Minmatar Starship Engineering', quantity: 3, unitPrice: 200, totalPrice: 600 },
      ],
      decryptorOptions: [
        {
          decryptorTypeId: null,
          decryptorName: 'No Decryptor',
          me: 2,
          te: 4,
          runs: 10,
          probability: 0.34,
          costPerAttempt: 2_300,
          expectedAttempts: 3,
          totalCost: 6_900,
          costBreakdown: { datacores: 800, decryptor: 0, copyCost: 1_000, inventionInstall: 500 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
        {
          decryptorTypeId: ACCELERANT_DECRYPTOR_TYPE_ID,
          decryptorName: 'Accelerant Decryptor',
          me: 4,
          te: 14,
          runs: 11,
          probability: 0.408,
          costPerAttempt: 52_300,
          expectedAttempts: 3,
          totalCost: 156_900,
          costBreakdown: { datacores: 800, decryptor: 50_000, copyCost: 1_000, inventionInstall: 500 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
      ],
    },
  ],
  summary: { totalInventionCost: 6_900, bestDecryptorTypeId: null, totalBpcKitCost: 6_900 },
}

const ISHTAR_BPC_KIT: BpcKit = {
  id: ISHTAR_PROJECT_ID,
  isT2: true,
  inventions: [
    {
      productTypeId: 12005,
      productName: 'Ishtar',
      baseProbability: 0.3,
      unknownReason: null,
      desiredSuccesses: 1,
      datacores: [
        { typeId: ELECTRONIC_ENGINEERING_DATACORE_TYPE_ID, typeName: 'Datacore - Electronic Engineering', quantity: 4, unitPrice: 9_000, totalPrice: 36_000 },
        { typeId: GALLENTEAN_STARSHIP_ENGINEERING_DATACORE_TYPE_ID, typeName: 'Datacore - Gallentean Starship Engineering', quantity: 5, unitPrice: 11_000, totalPrice: 55_000 },
      ],
      decryptorOptions: [
        {
          decryptorTypeId: null,
          decryptorName: 'No Decryptor',
          me: 2,
          te: 4,
          runs: 1,
          probability: 0.3,
          costPerAttempt: 125_000,
          expectedAttempts: 4,
          totalCost: 500_000,
          costBreakdown: { datacores: 91_000, decryptor: 0, copyCost: 30_000, inventionInstall: 4_000 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
        {
          decryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
          decryptorName: 'Attainment Decryptor',
          me: ISHTAR_ATTAINMENT_ME,
          te: ISHTAR_ATTAINMENT_TE,
          runs: 5,
          probability: 0.54,
          costPerAttempt: 206_250,
          expectedAttempts: ISHTAR_EXPECTED_ATTEMPTS,
          totalCost: ISHTAR_ATTAINMENT_TOTAL_COST,
          costBreakdown: { datacores: 91_000, decryptor: 81_250, copyCost: 30_000, inventionInstall: 4_000 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
      ],
    },
  ],
  summary: { totalInventionCost: ISHTAR_ATTAINMENT_TOTAL_COST, bestDecryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID, totalBpcKitCost: 437_800 },
}

type IshtarBpcKitResponse = 'pending' | 'failure' | 'loaded'

const applyToProjectLabel = en.industry.bpcKitTab.applyToProject

function bpcKitPath(projectId: string): string {
  return `/industry/projects/${projectId}/bpc-kit`
}

function respondToTheIshtarProject(ishtarBpcKitResponse: IshtarBpcKitResponse): void {
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (path === bpcKitPath(SABRE_PROJECT_ID)) return SABRE_BPC_KIT
    if (path === bpcKitPath(ISHTAR_PROJECT_ID)) {
      if (ishtarBpcKitResponse === 'pending') return new Promise<never>(() => {})
      if (ishtarBpcKitResponse === 'failure') throw new Error('ESI unavailable')
      return ISHTAR_BPC_KIT
    }
    return { id: ISHTAR_PROJECT_ID }
  })
}

async function mountTheIshtarTabAfterTheSabreKit(ishtarBpcKitResponse: IshtarBpcKitResponse): Promise<VueWrapper> {
  respondToTheIshtarProject(ishtarBpcKitResponse)
  await useProjectsStore().fetchBpcKit(SABRE_PROJECT_ID)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BpcKitTab, {
    global: { plugins: [i18n] },
    props: { projectId: ISHTAR_PROJECT_ID },
  })
  await flushPromises()
  return wrapper
}

function applyToProjectButton(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement> | undefined {
  return wrapper.findAll('button').find(button => button.text() === applyToProjectLabel)
}

function projectUpdateCalls(): unknown[][] {
  return vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'PATCH')
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('BpcKitTab opened on a project after the BPC kit of another project', () => {
  it('does not show the BPC kit of the previous project while the kit of the opened project is loading', async () => {
    const wrapper = await mountTheIshtarTabAfterTheSabreKit('pending')

    expect(wrapper.text()).not.toContain('Sabre')
    expect(wrapper.text()).not.toContain('Accelerant Decryptor')
    expect(wrapper.text()).not.toContain('156.90 K')
  })

  it('offers no way to apply the BPC kit of the previous project while the kit of the opened project is loading', async () => {
    const wrapper = await mountTheIshtarTabAfterTheSabreKit('pending')

    const applyButton = applyToProjectButton(wrapper)
    expect(applyButton === undefined || applyButton.element.disabled).toBe(true)
    await applyButton?.trigger('click')
    await flushPromises()
    expect(projectUpdateCalls()).toEqual([])
  })

  it('neither shows nor applies the BPC kit of the previous project when the kit of the opened project fails to load', async () => {
    const wrapper = await mountTheIshtarTabAfterTheSabreKit('failure')

    expect(wrapper.text()).not.toContain('Sabre')
    expect(wrapper.text()).not.toContain('Accelerant Decryptor')
    const applyButton = applyToProjectButton(wrapper)
    expect(applyButton === undefined || applyButton.element.disabled).toBe(true)
    await applyButton?.trigger('click')
    await flushPromises()
    expect(projectUpdateCalls()).toEqual([])
  })

  it('shows the BPC kit of the opened project once loaded and applies its values to that project', async () => {
    const wrapper = await mountTheIshtarTabAfterTheSabreKit('loaded')

    expect(wrapper.text()).toContain('Ishtar')
    expect(wrapper.text()).toContain('Attainment Decryptor')
    expect(wrapper.text()).not.toContain('Accelerant Decryptor')

    const applyButton = applyToProjectButton(wrapper)
    expect(applyButton?.element.disabled).toBe(false)
    await applyButton!.trigger('click')
    await flushPromises()

    const updates = projectUpdateCalls()
    expect(updates).toHaveLength(1)
    const [path, options] = updates[0] as [string, RequestInit]
    expect(path).toBe(`/industry/projects/${ISHTAR_PROJECT_ID}`)
    expect(JSON.parse(String(options.body))).toEqual({
      bpoCost: ISHTAR_ATTAINMENT_TOTAL_COST,
      meLevel: ISHTAR_ATTAINMENT_ME,
      teLevel: ISHTAR_ATTAINMENT_TE,
      inventionMaterials: [
        { typeId: ELECTRONIC_ENGINEERING_DATACORE_TYPE_ID, typeName: 'Datacore - Electronic Engineering', quantity: 8 },
        { typeId: GALLENTEAN_STARSHIP_ENGINEERING_DATACORE_TYPE_ID, typeName: 'Datacore - Gallentean Starship Engineering', quantity: 10 },
        { typeId: ATTAINMENT_DECRYPTOR_TYPE_ID, typeName: 'Attainment Decryptor', quantity: ISHTAR_EXPECTED_ATTEMPTS },
      ],
    })
  })
})
