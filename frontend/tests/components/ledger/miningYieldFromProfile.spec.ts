import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/i18n/locales/en.json'
import MiningEntriesTable from '@/components/ledger/MiningEntriesTable.vue'
import type { MiningEntry } from '@/stores/ledger'
import { apiRequest } from '@/services/api'
import {
  ASTRAHUS_STRUCTURE_ID,
  SCORDITE_TYPE_ID,
  VELDSPAR_TYPE_ID,
  i18nPlugin,
  miningEntry,
  mountTab,
  respondWith,
} from './miningLedgerHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROFILE_YIELD = 0.7236
const LEGACY_YIELD_STORAGE_KEY = 'miningReprocessYield'

const REPROCESSED_VELDSPAR = miningEntry({ id: 'veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 3_000, reprocessValue: 13.32, reprocessYield: PROFILE_YIELD, usage: 'sold' })
const SCORDITE_WITHOUT_YIELD = miningEntry({ id: 'scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 4_000, rawUnitPrice: 22.6, usage: 'sold' })

function mountTable(entries: MiningEntry[]) {
  setActivePinia(createPinia())
  return mount(MiningEntriesTable, {
    props: { entries, selectedStructureId: ASTRAHUS_STRUCTURE_ID },
    global: { plugins: [i18nPlugin()] },
  })
}

function reprocessHeader(wrapper: ReturnType<typeof mountTable>) {
  const header = wrapper.findAll('thead th').find(th => th.text().startsWith('Reproc.'))
  if (!header) throw new Error('No reprocess column')
  return header
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
  respondWith(() => Promise.resolve({ totals: { totalValue: 0, totalQuantity: 0 }, byUsage: {}, missingPriceTypeIds: [] }), () => Promise.resolve([REPROCESSED_VELDSPAR]))
})

describe('MiningEntriesTable reprocess column', () => {
  it('shows the yield of the profile carried by the entries in the column header', () => {
    const wrapper = mountTable([REPROCESSED_VELDSPAR, SCORDITE_WITHOUT_YIELD])

    expect(reprocessHeader(wrapper).text()).toBe('Reproc. 72.36%')
  })

  it('shows an unknown yield in the column header when no entry carries one', () => {
    const wrapper = mountTable([SCORDITE_WITHOUT_YIELD])

    expect(reprocessHeader(wrapper).text()).toBe('Reproc. ?')
  })
})

describe('MiningTab reprocessing yield', () => {
  it('has no yield input any more', async () => {
    const wrapper = await mountTab()

    expect(wrapper.find(`input[aria-label="${en.ledger.mining.yield}"]`).exists()).toBe(false)
  })

  it('requests the entries without any yield, structure or export tax, even when an old yield was saved in the browser', async () => {
    localStorage.setItem(LEGACY_YIELD_STORAGE_KEY, '50')

    await mountTab()

    const entriesRequests = vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint.startsWith('/ledger/mining/entries'))
    expect(entriesRequests.length).toBeGreaterThan(0)
    expect(entriesRequests.every(endpoint => !endpoint.includes('reprocessYield'))).toBe(true)
    expect(entriesRequests.every(endpoint => !endpoint.includes('exportTax=') && !endpoint.includes('structureId='))).toBe(true)
  })
})
