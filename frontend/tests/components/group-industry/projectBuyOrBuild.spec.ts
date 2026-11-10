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
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const BUY_OR_BUILD_PATH = `${PROJECT_PATH}/buy-or-build`
const WORKED_LINE_REFUSAL = 'The line "Fernite Composite" carries a linked job, a purchase or a contribution and cannot be removed from the plan.'
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

const FERNITE_COMPOSITE_BLACKLISTED = { ...FERNITE_COMPOSITE_BOUGHT, blacklisted: true }

type PatchOutcome = () => Promise<unknown>

async function mountSwitchable(rows: BuyOrBuildRow[], blacklistTypeIds: number[], patchOutcome: PatchOutcome = () => Promise.resolve({}), canSwitch = true): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((_endpoint: string, options?: RequestInit) =>
    Promise.resolve(options?.method === 'PATCH' ? patchOutcome() : projectBuyOrBuild(PROJECT_ID, rows)),
  )
  setActivePinia(createPinia())
  const wrapper = mount(ProjectBuyOrBuild, { props: { projectId: PROJECT_ID, canSwitch, blacklistTypeIds }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

async function clickSwitch(wrapper: VueWrapper, typeId: number): Promise<void> {
  const button = part(wrapper, typeId, 'switch')
  expect(button.exists()).toBe(true)
  if (button.exists()) await button.trigger('click')
  await flushPromises()
}

function patchesSent(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === PROJECT_PATH && options?.method === 'PATCH')
    .map(([, options]) => String(options?.body))
}

function comparisonReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === BUY_OR_BUILD_PATH).length
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

  it('shows no switch button without the right to switch, whatever the line', async () => {
    const wrapper = await mountSwitchable([HOBGOBLIN_II_BUILT, FERNITE_COMPOSITE_BLACKLISTED], [FERNITE_COMPOSITE_TYPE_ID], undefined, false)

    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'switch').exists()).toBe(false)
    expect(part(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'switch').exists()).toBe(false)
  })

  it('offers to switch a built line to buy and sends the blacklist of the project with its type added', async () => {
    const wrapper = await mountSwitchable([HOBGOBLIN_II_BUILT], [OGRE_II_TYPE_ID])

    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'switch')).toBe(text('groupIndustry.buyOrBuild.switch.toBuy'))
    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'blacklisted').exists()).toBe(false)

    await clickSwitch(wrapper, HOBGOBLIN_II_TYPE_ID)

    expect(patchesSent()).toEqual([JSON.stringify({ blacklistTypeIds: [OGRE_II_TYPE_ID, HOBGOBLIN_II_TYPE_ID] })])
  })

  it('marks a blacklisted line as bought by the blacklist and sends the blacklist without its type to switch it back to build', async () => {
    const wrapper = await mountSwitchable([FERNITE_COMPOSITE_BLACKLISTED], [HOBGOBLIN_II_TYPE_ID, FERNITE_COMPOSITE_TYPE_ID])

    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'blacklisted')).toBe(text('groupIndustry.buyOrBuild.switch.blacklisted'))
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, 'switch')).toBe(text('groupIndustry.buyOrBuild.switch.toBuild'))

    await clickSwitch(wrapper, FERNITE_COMPOSITE_TYPE_ID)

    expect(patchesSent()).toEqual([JSON.stringify({ blacklistTypeIds: [HOBGOBLIN_II_TYPE_ID] })])
  })

  it('disables the switch button while the project is being updated', async () => {
    const pending = deferred<unknown>()
    const wrapper = await mountSwitchable([HOBGOBLIN_II_BUILT], [], () => pending.promise)

    await clickSwitch(wrapper, HOBGOBLIN_II_TYPE_ID)
    const whileUpdating = part(wrapper, HOBGOBLIN_II_TYPE_ID, 'switch').attributes('disabled')
    pending.resolve({})
    await flushPromises()

    expect(whileUpdating).toBeDefined()
    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'switch').attributes('disabled')).toBeUndefined()
  })

  it('reads the comparison again and emits switched once the blacklist is saved', async () => {
    const wrapper = await mountSwitchable([HOBGOBLIN_II_BUILT], [])
    const readsBeforeTheSwitch = comparisonReads()

    await clickSwitch(wrapper, HOBGOBLIN_II_TYPE_ID)

    expect([readsBeforeTheSwitch, comparisonReads()]).toEqual([1, 2])
    expect(wrapper.emitted('switched')).toHaveLength(1)
    expect(wrapper.find('[data-testid="buy-or-build-switch-error"]').exists()).toBe(false)
  })

  it('shows the message of the server in an alert and does not emit switched when the blacklist is refused', async () => {
    const wrapper = await mountSwitchable([FERNITE_COMPOSITE_BLACKLISTED], [FERNITE_COMPOSITE_TYPE_ID], () => Promise.reject(new Error(WORKED_LINE_REFUSAL)))

    await clickSwitch(wrapper, FERNITE_COMPOSITE_TYPE_ID)

    const alert = wrapper.get('[data-testid="buy-or-build-switch-error"]')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toContain(text('groupIndustry.buyOrBuild.switch.error'))
    expect(alert.text()).toContain(WORKED_LINE_REFUSAL)
    expect(wrapper.emitted('switched')).toBeUndefined()
    expect(comparisonReads()).toBe(1)
  })
})
