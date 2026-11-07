import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectBuyOrBuild from '@/components/group-industry/ProjectBuyOrBuild.vue'
import { apiRequest } from '@/services/api'
import type { BuyOrBuildRow } from '@/stores/group-industry/types'
import {
  FERNITE_COMPOSITE_BOUGHT,
  FERNITE_COMPOSITE_TYPE_ID,
  HOBGOBLIN_II_BUILT,
  HOBGOBLIN_II_TYPE_ID,
  OGRE_II_TYPE_ID,
  OGRE_II_WITHOUT_ROUTE,
  projectBuyOrBuild,
  WARRIOR_II_PARTIAL_PRICE,
  WARRIOR_II_TYPE_ID,
} from '../../buyOrBuildRows'
import { deferred } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const BLANK = /[\u00a0\u202f]/g

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string, params: Record<string, unknown> = {}): string {
  return i18n.global.t(key, params)
}

async function mountBuyOrBuild(rows: BuyOrBuildRow[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(projectBuyOrBuild(PROJECT_ID, rows))
  setActivePinia(createPinia())
  const wrapper = mount(ProjectBuyOrBuild, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function part(wrapper: VueWrapper, typeId: number, name: string) {
  return wrapper.find(`[data-testid="buy-or-build-row-${typeId}-${name}"]`)
}

function partText(wrapper: VueWrapper, typeId: number, name: string): string {
  const found = part(wrapper, typeId, name)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function showsUnknownMarker(wrapper: VueWrapper, typeId: number, name: string): boolean {
  const found = part(wrapper, typeId, name)
  return found.exists() && found.find(`[title="${en.common.unknownValue}"]`).exists()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectBuyOrBuild', () => {
  it('shows a built intermediate with its amounts per unit, the build choice, the saving and the import rate', async () => {
    const wrapper = await mountBuyOrBuild([HOBGOBLIN_II_BUILT])

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(`/industry/projects/${PROJECT_ID}/buy-or-build`)
    expect(wrapper.get(`[data-testid="buy-or-build-row-${HOBGOBLIN_II_TYPE_ID}"]`).text()).toContain('Hobgoblin II')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'quantity')).toContain('50')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'build')).toContain('33.74 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'jita')).toContain('31.90 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'import')).toContain('13.50 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'buy')).toContain('45.40 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'choice')).toContain(text('groupIndustry.buyOrBuild.choice.build'))
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'saving')).toContain('583.00 K')
    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'without-import').exists()).toBe(true)
    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'build-reused').exists()).toBe(false)
    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'saving-reused').exists()).toBe(false)
    expect(wrapper.get('[data-testid="buy-or-build-rate"]').text()).toContain('150')
  })

  it('shows a bought intermediate with the cost if the surplus is reused and the saving it would bring, without the without-import note', async () => {
    const wrapper = await mountBuyOrBuild([FERNITE_COMPOSITE_BOUGHT])

    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'build')).toContain('314.00 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'build-reused')).toContain('31.40 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'jita')).toContain('30.90 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'import')).toContain('1.35 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'import')).toMatch(/1\s*m³\s*×\s*9/)
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'buy')).toContain('32.25 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'choice')).toContain(text('groupIndustry.buyOrBuild.choice.buy'))
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'saving')).toContain('14.09 M')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'saving-reused')).toContain('42.50 K')
    expect(part(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'without-import').exists()).toBe(false)
  })

  it('shows an unknown import and buy cost as a dash with the unknown marker, never 0, and an unknown choice without saving', async () => {
    const wrapper = await mountBuyOrBuild([OGRE_II_WITHOUT_ROUTE])

    expect(showsUnknownMarker(wrapper, OGRE_II_TYPE_ID, 'import')).toBe(true)
    expect(showsUnknownMarker(wrapper, OGRE_II_TYPE_ID, 'buy')).toBe(true)
    expect(showsUnknownMarker(wrapper, OGRE_II_TYPE_ID, 'jita')).toBe(false)
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'jita')).toContain('38.70 K')
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'import')).not.toMatch(/\b0\b/)
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'buy')).not.toMatch(/\b0\b/)
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'choice')).toContain(text('groupIndustry.buyOrBuild.choice.unknown'))
    expect(part(wrapper, OGRE_II_TYPE_ID, 'saving').exists()).toBe(false)
    expect(part(wrapper, OGRE_II_TYPE_ID, 'without-import').exists()).toBe(true)
  })

  it('marks a partial Jita price with its coverage', async () => {
    const wrapper = await mountBuyOrBuild([WARRIOR_II_PARTIAL_PRICE])

    expect(wrapper.get(`[data-testid="buy-or-build-row-${WARRIOR_II_TYPE_ID}"]`).text()).toContain(text('industry.partialPrice.marker', { percent: 60 }))
  })

  it('shows the loading state while the rows are being read', async () => {
    vi.mocked(apiRequest).mockReturnValue(deferred<unknown>().promise)
    setActivePinia(createPinia())

    const wrapper = mount(ProjectBuyOrBuild, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.find('[data-testid="buy-or-build-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="buy-or-build-empty"]').exists()).toBe(false)
  })

  it('shows the error of the request instead of an empty list', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Buy or build unavailable: project not found'))
    setActivePinia(createPinia())

    const wrapper = mount(ProjectBuyOrBuild, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.get('[data-testid="buy-or-build-error"]').text()).toContain('Buy or build unavailable: project not found')
    expect(wrapper.find('[data-testid="buy-or-build-empty"]').exists()).toBe(false)
  })

  it('says there is nothing to compare when the plan has no intermediate', async () => {
    const wrapper = await mountBuyOrBuild([])

    expect(wrapper.find('[data-testid="buy-or-build-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="buy-or-build-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="buy-or-build-loading"]').exists()).toBe(false)
  })
})
