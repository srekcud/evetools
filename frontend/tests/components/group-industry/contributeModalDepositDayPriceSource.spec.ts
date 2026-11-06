import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-154'

function materialBomItem(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-item',
    typeId: 0,
    typeName: '',
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    remainingQuantity: 0,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    ...overrides,
  }
}

function hullRunProject(createdAt: string): GroupProject {
  return {
    id: PROJECT_ID,
    name: 'Hull run',
    status: 'in_progress',
    shortLinkCode: 'hull154',
    containerName: null,
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: 98000001,
    membersCount: 2,
    items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
    totalBomValue: 1_250_000,
    fulfillmentPercent: 40,
    approvedContributionValue: 500_000,
    brokerFeePercent: 3.6,
    salesTaxPercent: 3.6,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt,
    myRole: 'member',
    myStatus: 'accepted',
    private: false,
    notes: null,
    acceptsOutsideFavoriteSystem: false,
    minimumOutsideSaving: 5,
  }
}

const TRITANIUM_AT_BOM_PRICE = materialBomItem({
  id: 'bom-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 10_000,
  fulfilledQuantity: 9_900,
  remainingQuantity: 100,
  fulfillmentPercent: 99,
  estimatedPrice: 12.5,
  estimatedTotal: 125_000,
})

const ISOGEN_AT_ZERO_BOM_PRICE = materialBomItem({
  id: 'bom-isogen',
  typeId: 37,
  typeName: 'Isogen',
  requiredQuantity: 2_000,
  fulfilledQuantity: 1_700,
  remainingQuantity: 300,
  fulfillmentPercent: 85,
  estimatedPrice: 0,
  estimatedTotal: 0,
})

const MORPHITE_WITHOUT_BOM_PRICE = materialBomItem({
  id: 'bom-morphite',
  typeId: 11399,
  typeName: 'Morphite',
  requiredQuantity: 500,
  fulfilledQuantity: 460,
  remainingQuantity: 40,
  fulfillmentPercent: 92,
})

type ModalOptions = {
  locale?: 'en' | 'fr'
}

async function openModalFor(bomItem: BomItem, options: ModalOptions = {}): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue({})
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = hullRunProject('2026-10-01T12:00:00+00:00')
  projectStore.bomItems = [TRITANIUM_AT_BOM_PRICE, ISOGEN_AT_ZERO_BOM_PRICE, MORPHITE_WITHOUT_BOM_PRICE]
  const i18n = createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem: bomItem },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function modalText(wrapper: VueWrapper): string {
  return wrapper.text().replace(/\s+/g, ' ')
}

function estimatedValueInput(wrapper: VueWrapper, label: string): DOMWrapper<HTMLInputElement> | undefined {
  const field = wrapper.findAll('label').find(candidate => candidate.text().startsWith(label))?.element.parentElement
  if (!field) return undefined
  return wrapper.findAll<HTMLInputElement>('input').find(input => field.contains(input.element))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ContributeModal price source of a material deposit', () => {
  it('no longer names the BOM price frozen at project creation, its freezing date nor a BOM price method', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE)

    expect(modalText(wrapper)).not.toContain('BOM price, frozen at project creation')
    expect(modalText(wrapper)).not.toContain('Frozen on')
    expect(modalText(wrapper)).not.toContain('Method: BOM price × quantity')
    expect(modalText(wrapper)).not.toMatch(/weighted/i)
  })

  it('no longer names the French BOM price frozen at project creation nor its freezing date', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).not.toContain('Prix BOM, figé à la création du projet')
    expect(modalText(wrapper)).not.toContain('Figé le')
    expect(modalText(wrapper)).not.toContain('Méthode : prix BOM × quantité')
  })

  it('names the Jita price of the deposit day, 5th percentile, for a line with a BOM price', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).toContain("Prix Jita du jour d'apport (percentile 5 %)")
  })

  it('names the same deposit-day price for a line without BOM price and asks for no estimated value', async () => {
    const wrapper = await openModalFor(MORPHITE_WITHOUT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).toContain("Prix Jita du jour d'apport (percentile 5 %)")
    expect(estimatedValueInput(wrapper, fr.groupIndustry.contribute.estimatedValue)).toBeUndefined()
  })

  it('leaks no raw translation key in English nor in French', async () => {
    const english = await openModalFor(TRITANIUM_AT_BOM_PRICE)
    const french = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(english)).not.toContain('groupIndustry.contribute')
    expect(modalText(french)).not.toContain('groupIndustry.contribute')
  })
})
