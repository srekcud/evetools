import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BlueprintLibraryTab from '@/components/industry/BlueprintLibraryTab.vue'
import { useIndustryStore } from '@/stores/industry'
import type { BlueprintLibraryEntry } from '@/stores/industry/types'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function entry(overrides: Partial<BlueprintLibraryEntry>): BlueprintLibraryEntry {
  return {
    typeId: 1, name: 'Drake Blueprint', kind: 'bpo', me: 10, te: 20, runs: null,
    scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, divisionName: null, count: 1,
    usedByRunningJob: false, productTypeId: 24698, productName: 'Drake', ...overrides,
  }
}

const DRAKE_BPO = entry({})
const HAMMERHEAD_BPC = entry({ typeId: 3, name: 'Hammerhead II Blueprint', kind: 'bpc', me: 2, te: 4, runs: 50, productTypeId: 2185, productName: 'Hammerhead II' })
const COMPOSITE_FORMULA = entry({ typeId: 46166, name: 'Composite Reaction Formula', kind: 'bpr', me: 0, te: 0, productTypeId: 16670, productName: 'Crystalline Carbonide' })
const NO_PRODUCT_BPO = entry({ typeId: 5, name: 'Test Blueprint', productTypeId: null, productName: null })
const BUSY_VEXOR_BPC = entry({ typeId: 4, name: 'Vexor Blueprint', kind: 'bpc', me: 6, te: 12, runs: 25, usedByRunningJob: true, productTypeId: 626, productName: 'Vexor' })
const UNNAMED_PRODUCT_BPO = entry({ typeId: 6, name: 'Mystery Blueprint', productTypeId: 99, productName: 'unknown_type' })

const ROWS = '[data-testid="blueprint-row"]'
const ROW_CHECKBOX = '[data-testid="blueprint-select"]'
const SELECT_ALL = '[data-testid="blueprints-select-all"]'
const CREATE_PROJECT = '[data-testid="blueprints-create-project"]'
const SEARCH = '[data-testid="blueprints-search"]'

function createLabel(count: number): string {
  return en.industry.blueprints.createProject.replace('{count}', String(count))
}

async function mountTab(blueprints: BlueprintLibraryEntry[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(() => Promise.resolve({ corporationState: 'known', blueprints }))
  setActivePinia(createPinia())
  const wrapper = mount(BlueprintLibraryTab, { global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] } })
  await flushPromises()
  return wrapper
}

async function tick(wrapper: VueWrapper, rowIndex: number): Promise<void> {
  await wrapper.findAll(ROWS)[rowIndex].find(ROW_CHECKBOX).setValue(true)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  setActivePinia(createPinia())
  useIndustryStore().navigationIntent = null
})

describe('BlueprintLibraryTab selection to a draft project (issue 903)', () => {
  it('disables the create button while nothing is selected', async () => {
    const wrapper = await mountTab([DRAKE_BPO, HAMMERHEAD_BPC])

    expect(wrapper.findAll(ROW_CHECKBOX)).toHaveLength(2)
    expect(wrapper.find(CREATE_PROJECT).attributes('disabled')).toBeDefined()
  })

  it('shows the selection count on the create button once rows are selected', async () => {
    const wrapper = await mountTab([DRAKE_BPO, HAMMERHEAD_BPC])

    await tick(wrapper, 0)
    await tick(wrapper, 1)

    const button = wrapper.find(CREATE_PROJECT)
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.text()).toBe(createLabel(2))
  })

  it('opens the project creation with one pre-filled item per selected blueprint', async () => {
    const wrapper = await mountTab([DRAKE_BPO, HAMMERHEAD_BPC])
    await tick(wrapper, 0)
    await tick(wrapper, 1)

    await wrapper.find(CREATE_PROJECT).trigger('click')

    expect(useIndustryStore().navigationIntent).toEqual({
      target: 'projects',
      prefillItems: [
        { typeId: 24698, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1 },
        { typeId: 2185, typeName: 'Hammerhead II', meLevel: 2, teLevel: 4, runs: 50 },
      ],
    })
  })

  it('pre-fills a reaction formula with its reaction product, no efficiency and one run', async () => {
    const wrapper = await mountTab([COMPOSITE_FORMULA])
    await tick(wrapper, 0)

    await wrapper.find(CREATE_PROJECT).trigger('click')

    expect(useIndustryStore().navigationIntent).toEqual({
      target: 'projects',
      prefillItems: [{ typeId: 16670, typeName: 'Crystalline Carbonide', meLevel: 0, teLevel: 0, runs: 1 }],
    })
  })

  it('keeps a blueprint without product unselectable', async () => {
    const wrapper = await mountTab([NO_PRODUCT_BPO])

    expect(wrapper.find(ROW_CHECKBOX).attributes('disabled')).toBeDefined()
  })

  it('lets a blueprint held by a running job be selected', async () => {
    const wrapper = await mountTab([BUSY_VEXOR_BPC])

    await tick(wrapper, 0)

    expect(wrapper.find(CREATE_PROJECT).attributes('disabled')).toBeUndefined()
  })

  it('names a product missing from the SDE with the type id fallback', async () => {
    const wrapper = await mountTab([UNNAMED_PRODUCT_BPO])
    await tick(wrapper, 0)

    await wrapper.find(CREATE_PROJECT).trigger('click')

    const intent = useIndustryStore().navigationIntent
    expect(intent?.target === 'projects' ? intent.prefillItems?.[0].typeName : undefined).toBe(en.common.unknownTypeName.replace('{typeId}', '99'))
  })

  it('selects every visible blueprint with a product from the header checkbox, nothing hidden by the search', async () => {
    const wrapper = await mountTab([DRAKE_BPO, HAMMERHEAD_BPC, NO_PRODUCT_BPO])
    await wrapper.find(SEARCH).setValue('blueprint')
    await wrapper.find(SEARCH).setValue('hammerhead')

    await wrapper.find(SELECT_ALL).setValue(true)
    await wrapper.find(SEARCH).setValue('')

    expect(wrapper.find(CREATE_PROJECT).text()).toBe(createLabel(1))
  })

  it('counts only the selectable rows when selecting all visible rows', async () => {
    const wrapper = await mountTab([DRAKE_BPO, HAMMERHEAD_BPC, NO_PRODUCT_BPO])

    await wrapper.find(SELECT_ALL).setValue(true)

    expect(wrapper.find(CREATE_PROJECT).text()).toBe(createLabel(2))
  })

  it('drops a selected blueprint from the selection when a filter hides it', async () => {
    const wrapper = await mountTab([DRAKE_BPO, HAMMERHEAD_BPC])
    await tick(wrapper, 0)

    await wrapper.find(SEARCH).setValue('hammerhead')
    await wrapper.find(SEARCH).setValue('')

    expect(wrapper.find(CREATE_PROJECT).attributes('disabled')).toBeDefined()
  })

  it('changes nothing for a user who selects nothing', async () => {
    await mountTab([DRAKE_BPO])

    expect(useIndustryStore().navigationIntent).toBeNull()
  })
})
