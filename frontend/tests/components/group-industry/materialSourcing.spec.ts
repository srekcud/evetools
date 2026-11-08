import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MaterialSourcing from '@/components/group-industry/MaterialSourcing.vue'
import { apiRequest } from '@/services/api'
import type { MaterialSourcingRow, ProjectMaterialSourcing } from '@/stores/group-industry/types'
import {
  MEXALLON_FALLBACK_TO_JITA,
  MEXALLON_TYPE_ID,
  MORPHITE_AT_STRUCTURE,
  MORPHITE_TYPE_ID,
  NOCXIUM_TYPE_ID,
  NOCXIUM_UNKNOWN,
  projectMaterialSourcing,
  TRITANIUM_AT_JITA,
  TRITANIUM_TYPE_ID,
} from '../../materialSourcingRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const BLANK = new RegExp('[\\u00a0\\u202f]', 'g')
const ALL_ROWS = [TRITANIUM_AT_JITA, MORPHITE_AT_STRUCTURE, NOCXIUM_UNKNOWN, MEXALLON_FALLBACK_TO_JITA]

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string, params: Record<string, unknown> = {}): string {
  return i18n.global.t(key, params)
}

async function mountSourcing(rows: MaterialSourcingRow[], overrides: Partial<ProjectMaterialSourcing> = {}): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(projectMaterialSourcing(PROJECT_ID, rows, overrides))
  setActivePinia(createPinia())
  const wrapper = mount(MaterialSourcing, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function part(wrapper: VueWrapper, typeId: number, name: string) {
  return wrapper.find(`[data-testid="material-sourcing-row-${typeId}-${name}"]`)
}

function partText(wrapper: VueWrapper, typeId: number, name: string): string {
  const found = part(wrapper, typeId, name)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function showsUnknownMarker(wrapper: VueWrapper, typeId: number, name: string): boolean {
  const found = part(wrapper, typeId, name)
  return found.exists() && found.find(`[title="${en.common.unknownValue}"]`).exists()
}

function totalText(wrapper: VueWrapper, name: string): string {
  return wrapper.get(`[data-testid="material-sourcing-total-${name}"]`).text().replace(BLANK, ' ')
}

enableAutoUnmount(afterEach)

const writeText = vi.fn()

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  writeText.mockReset()
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('MaterialSourcing', () => {
  it('compares each material remaining to source: Jita with import, the structure, the cheaper side and the saving', async () => {
    const wrapper = await mountSourcing(ALL_ROWS)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(`/industry/projects/${PROJECT_ID}/material-sourcing`)
    expect(wrapper.get(`[data-testid="material-sourcing-row-${TRITANIUM_TYPE_ID}"]`).text()).toContain('Tritanium')
    expect(partText(wrapper, TRITANIUM_TYPE_ID, 'quantity')).toContain('12,000')
    expect(partText(wrapper, TRITANIUM_TYPE_ID, 'jita')).toContain('96.00 K')
    expect(partText(wrapper, TRITANIUM_TYPE_ID, 'structure')).toContain('120.00 K')
    expect(partText(wrapper, TRITANIUM_TYPE_ID, 'side')).toContain(text('groupIndustry.materialSourcing.side.jita'))
    expect(partText(wrapper, TRITANIUM_TYPE_ID, 'saving')).toContain('24.00 K')
    expect(partText(wrapper, MORPHITE_TYPE_ID, 'side')).toContain(text('groupIndustry.materialSourcing.side.structure'))
    expect(partText(wrapper, MORPHITE_TYPE_ID, 'saving')).toContain('151.41 K')
    expect(part(wrapper, TRITANIUM_TYPE_ID, 'fallback').exists()).toBe(false)
  })

  it('shows an unknown marker, never 0, for a price or a volume that is missing', async () => {
    const wrapper = await mountSourcing(ALL_ROWS)

    expect(showsUnknownMarker(wrapper, NOCXIUM_TYPE_ID, 'jita')).toBe(true)
    expect(showsUnknownMarker(wrapper, NOCXIUM_TYPE_ID, 'structure')).toBe(true)
    expect(partText(wrapper, NOCXIUM_TYPE_ID, 'side')).toContain(text('groupIndustry.materialSourcing.side.unknown'))
    expect(part(wrapper, NOCXIUM_TYPE_ID, 'saving').exists()).toBe(false)
    expect(showsUnknownMarker(wrapper, MEXALLON_TYPE_ID, 'structure')).toBe(true)
  })

  it('says a material without structure price goes to Jita', async () => {
    const wrapper = await mountSourcing(ALL_ROWS)

    expect(partText(wrapper, MEXALLON_TYPE_ID, 'side')).toContain(text('groupIndustry.materialSourcing.side.jita'))
    expect(partText(wrapper, MEXALLON_TYPE_ID, 'fallback')).toContain(text('groupIndustry.materialSourcing.structureFallback'))
  })

  it('shows the three totals, and an unknown marker for a total that the API leaves null', async () => {
    const known = await mountSourcing(ALL_ROWS)

    expect(totalText(known, 'jita')).toContain('2.75 M')
    expect(totalText(known, 'structure')).toContain('2.62 M')
    expect(totalText(known, 'best')).toContain('2.60 M')

    const unknown = await mountSourcing(ALL_ROWS, { totals: { jitaWithImport: null, structure: null, best: null } })

    expect(unknown.get('[data-testid="material-sourcing-total-structure"]').find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
  })

  it('copies the Jita list in multibuy format with the remaining quantities, materials with no better side included', async () => {
    const wrapper = await mountSourcing(ALL_ROWS)

    await wrapper.get('[data-testid="material-sourcing-copy-jita"]').trigger('click')

    expect(writeText).toHaveBeenCalledExactlyOnceWith('Tritanium\t12000\nNocxium\t600\nMexallon\t100')
    expect(wrapper.get('[data-testid="material-sourcing-copy-jita"]').text()).toContain(text('shopping.copied'))
  })

  it('copies the structure list in multibuy format with the remaining quantities', async () => {
    const wrapper = await mountSourcing(ALL_ROWS)

    await wrapper.get('[data-testid="material-sourcing-copy-structure"]').trigger('click')

    expect(writeText).toHaveBeenCalledExactlyOnceWith('Morphite\t147')
  })

  it('offers no structure button when no material goes to the structure', async () => {
    const wrapper = await mountSourcing([TRITANIUM_AT_JITA])

    expect(wrapper.find('[data-testid="material-sourcing-copy-jita"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="material-sourcing-copy-structure"]').exists()).toBe(false)
  })

  it('tells the reader to choose a preferred market structure when the profile has none', async () => {
    const wrapper = await mountSourcing([TRITANIUM_AT_JITA], { structureId: null, structureName: null, structureSynced: false, structureLastSync: null })

    expect(wrapper.get('[data-testid="material-sourcing-structure-hint"]').text()).toContain(text('groupIndustry.materialSourcing.noStructure'))
  })

  it('tells the reader to sync the market of a preferred structure that has no cached prices', async () => {
    const wrapper = await mountSourcing([MEXALLON_FALLBACK_TO_JITA], { structureSynced: false, structureLastSync: null })

    expect(wrapper.get('[data-testid="material-sourcing-structure-hint"]').text()).toContain(text('groupIndustry.materialSourcing.structureNotSynced'))
  })

  it('names the compared structure and the import rate when the market is synced', async () => {
    const wrapper = await mountSourcing(ALL_ROWS)

    expect(wrapper.find('[data-testid="material-sourcing-structure-hint"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="material-sourcing-structure"]').text()).toContain('Perimeter - Tranquility Trading Tower')
    expect(wrapper.get('[data-testid="material-sourcing-rate"]').text()).toContain('150')
  })

  it('shows an empty state when nothing remains to source', async () => {
    const wrapper = await mountSourcing([])

    expect(wrapper.find('[data-testid="material-sourcing-empty"]').exists()).toBe(true)
  })

  it('shows the error of a failed read instead of rows', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('boom'))
    setActivePinia(createPinia())
    const wrapper = mount(MaterialSourcing, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.get('[data-testid="material-sourcing-error"]').text()).toContain('boom')
  })
})
