import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { dateFormat } from '@/composables/useFormatters'
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
  createdAt?: string
}

async function openModalFor(bomItem: BomItem, options: ModalOptions = {}): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue({})
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = hullRunProject(options.createdAt ?? '2026-10-01T12:00:00+00:00')
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
  dateFormat.value = 'eu'
})

afterEach(() => {
  dateFormat.value = 'eu'
})

describe('ContributeModal price source of a material with a BOM price, in English', () => {
  it('previews the BOM price times the quantity', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE)

    expect(modalText(wrapper)).toContain('1,250 ISK')
  })

  it('names the BOM price frozen at project creation and its Jita source', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE)

    expect(modalText(wrapper)).toContain('BOM price, frozen at project creation')
    expect(modalText(wrapper)).toContain('Jita, cheapest 5% of sell orders')
  })

  it('names the BOM price times quantity as the valuation method', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE)

    expect(modalText(wrapper)).toContain('Method: BOM price × quantity')
  })

  it('no longer mentions a weighted price', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE)

    expect(modalText(wrapper)).not.toMatch(/weighted/i)
  })

  it('gives the creation date of the current project as the date the price was frozen, in the EU date format', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { createdAt: '2026-10-01T12:00:00+00:00' })

    expect(modalText(wrapper)).toContain('Frozen on 01/10/26')
  })

  it('gives the creation date of the current project in the US date format when it is chosen', async () => {
    dateFormat.value = 'us'

    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { createdAt: '2026-09-14T12:00:00+00:00' })

    expect(modalText(wrapper)).toContain('Frozen on 09/14/26')
  })

  it('names the BOM price of a material priced at zero, a known price, without a valuation total', async () => {
    const wrapper = await openModalFor(ISOGEN_AT_ZERO_BOM_PRICE)

    expect(modalText(wrapper)).toContain('BOM price, frozen at project creation')
    expect(modalText(wrapper)).toContain('Frozen on 01/10/26')
    expect(modalText(wrapper)).not.toContain(en.groupIndustry.contribute.totalValuation)
  })
})

describe('ContributeModal price source of a material with a BOM price, in French', () => {
  it('names the BOM price frozen at project creation, its Jita source and the valuation method', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).toContain('Prix BOM, figé à la création du projet')
    expect(modalText(wrapper)).toContain('Jita, 5 % des ordres de vente les moins chers')
    expect(modalText(wrapper)).toContain('Méthode : prix BOM × quantité')
  })

  it('gives the creation date of the current project as the date the price was frozen', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr', createdAt: '2026-10-01T12:00:00+00:00' })

    expect(modalText(wrapper)).toContain('Figé le 01/10/26')
  })

  it('mentions neither a weighted price nor a « pondéré » price', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).not.toMatch(/weighted/i)
    expect(modalText(wrapper)).not.toMatch(/pond[ée]r/i)
  })
})

describe('ContributeModal for a material without BOM price, an unknown value', () => {
  it('shows the estimated value field alone, without the BOM price label nor the frozen date', async () => {
    const wrapper = await openModalFor(MORPHITE_WITHOUT_BOM_PRICE)

    expect(estimatedValueInput(wrapper, en.groupIndustry.contribute.estimatedValue), 'estimated value input').toBeDefined()
    expect(modalText(wrapper)).not.toContain('BOM price, frozen at project creation')
    expect(modalText(wrapper)).not.toContain('Frozen on')
    expect(modalText(wrapper)).not.toContain('Method: BOM price × quantity')
  })

  it('shows neither the French BOM price label nor the frozen date', async () => {
    const wrapper = await openModalFor(MORPHITE_WITHOUT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).not.toContain('Prix BOM, figé à la création du projet')
    expect(modalText(wrapper)).not.toContain('Figé le')
  })
})

describe('ContributeModal labels of the auto-calculated BOM price', () => {
  it('reads « Auto-calculated » and « Unit price: » in English from translation keys that exist', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE)

    expect(modalText(wrapper)).toContain('Auto-calculated')
    expect(modalText(wrapper)).toContain('Unit price:')
    expect(modalText(wrapper)).not.toContain('groupIndustry.contribute')
  })

  it('reads « Calculé automatiquement » and « Prix unitaire : » in French', async () => {
    const wrapper = await openModalFor(TRITANIUM_AT_BOM_PRICE, { locale: 'fr' })

    expect(modalText(wrapper)).toContain('Calculé automatiquement')
    expect(modalText(wrapper)).toContain('Prix unitaire :')
    expect(modalText(wrapper)).not.toContain('Auto-calculated')
    expect(modalText(wrapper)).not.toContain('Unit price')
    expect(modalText(wrapper)).not.toContain('groupIndustry.contribute')
  })
})
