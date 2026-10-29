import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import MiningTab from '@/components/ledger/MiningTab.vue'
import type { MiningEntry } from '@/stores/ledger'
import { apiRequest } from '@/services/api'

export const VELDSPAR_TYPE_ID = 1230
export const SCORDITE_TYPE_ID = 1228
export const TRITANIUM_TYPE_ID = 34
export const MYSTERY_ORE_TYPE_ID = 81143
export const UNLOADED_ORE_TYPE_ID = 81144
export const JITA_SYSTEM_ID = 30000142
export const PERIMETER_SYSTEM_ID = 30000144
export const ASTRAHUS_STRUCTURE_ID = 1022734985679

export function miningEntry(overrides: Partial<MiningEntry>): MiningEntry {
  return {
    id: 'entry',
    characterId: 2112000001,
    characterName: 'Ilyra Castell',
    date: '2026-10-20T00:00:00+00:00',
    typeId: VELDSPAR_TYPE_ID,
    typeName: 'Veldspar',
    solarSystemId: JITA_SYSTEM_ID,
    solarSystemName: 'Jita',
    quantity: 1,
    unitPrice: null,
    totalValue: null,
    compressedTypeId: null,
    compressedTypeName: null,
    compressedUnitPrice: null,
    compressedEquivalentPrice: null,
    structureUnitPrice: null,
    structureCompressedUnitPrice: null,
    structureCompressedEquivalentPrice: null,
    reprocessValue: null,
    structureReprocessValue: null,
    rawUnitPrice: null,
    usage: 'unknown',
    linkedProjectId: null,
    linkedCorpProjectId: null,
    syncedAt: '2026-10-21T08:00:00+00:00',
    ...overrides,
  }
}

export function i18nPlugin() {
  return createI18n({ legacy: false, locale: 'en', messages: { en } })
}

export function respondWith(stats: () => Promise<unknown>, entries: () => Promise<unknown>) {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint.startsWith('/ledger/mining/stats')) return stats()
    if (endpoint.startsWith('/ledger/mining/entries')) return entries()
    return new Promise(() => {})
  })
}

export async function mountTab() {
  setActivePinia(createPinia())
  const wrapper = mount(MiningTab, { props: { selectedDays: 30 }, global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  return wrapper
}

export type MiningTabWrapper = Awaited<ReturnType<typeof mountTab>>

export function card(wrapper: MiningTabWrapper, label: string) {
  const found = wrapper.findAll('div.rounded-xl').find(div => div.find('span').exists() && div.get('span').text() === label)
  if (!found) throw new Error(`No card labelled ${label}`)
  return found
}

export function cardValue(wrapper: MiningTabWrapper, label: string) {
  return card(wrapper, label).element.children[1] as HTMLElement
}

export function cardShare(wrapper: MiningTabWrapper, label: string) {
  return card(wrapper, label).element.children[2] as HTMLElement
}
