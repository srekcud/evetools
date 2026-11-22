import { defineComponent, h } from 'vue'
import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import { apiRequest } from '@/services/api'
import type { PlacedAction } from '@/stores/group-industry/types'
import IndustryTodo from '@/views/IndustryTodo.vue'
import { deferred } from '../deferred'
import {
  ANYWHERE_PLACE,
  AZBEL_PLACE,
  EXAMPLE_ACTIONS,
  IBIS_PROJECT_ID,
  ISHTAR_PROJECT_ID,
  JITA_PLACE,
  LAUNCH_RAM_AT_AZBEL,
  SOURCE_TRITANIUM_400_AT_JITA,
  SOURCE_ZYDRINE_50_AT_JITA,
  placedAction,
  unknownPlace,
} from '../todoPlaceFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

const writeText = vi.fn<(text: string) => Promise<void>>()

async function mountView(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(IndustryTodo, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: MainLayoutStub,
        IndustryModuleTabs: true,
        RouterLink: RouterLinkStub,
      },
    },
  })
  await flushPromises()
  return wrapper
}

async function mountWith(actions: PlacedAction[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(actions as never)
  return mountView(locale)
}

function groupTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="todo-place-header"]').map(header => header.text())
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

const BUY_TRITANIUM_250_WITH_OTHERS = placedAction({
  ...SOURCE_TRITANIUM_400_AT_JITA,
  quantity: 250,
  reservedByOthers: [
    { characterName: 'Mira Kessel', quantity: 150 },
    { characterName: 'Kaelen Dray', quantity: 100 },
  ],
})

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('IndustryTodo view', () => {
  it('marks the to do page as current, shows the loading state, then reads the actions of every project once', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise as never)
    setActivePinia(createPinia())
    const wrapper = mount(IndustryTodo, {
      global: {
        plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
        stubs: { MainLayout: MainLayoutStub, IndustryModuleTabs: true, RouterLink: RouterLinkStub },
      },
    })
    await flushPromises()
    const whileLoading = wrapper.find('[data-testid="todo-loading"]').exists()
    pending.resolve([])
    await flushPromises()

    expect({
      active: wrapper.getComponent(IndustryModuleTabs).props('active'),
      whileLoading,
      loadingAfter: wrapper.find('[data-testid="todo-loading"]').exists(),
    }).toEqual({ active: 'todo', whileLoading: true, loadingAfter: false })
    expect(apiRequest).toHaveBeenCalledExactlyOnceWith('/industry/place-actions')
  })

  it('orders the groups as the server does and names each place with its system, its reason or Anywhere', async () => {
    const wrapper = await mountWith([
      ...EXAMPLE_ACTIONS,
      placedAction({ id: 'x1', code: 'source_material', place: unknownPlace('market_structure_unset'), typeName: 'Pyerite', typeId: 35, quantity: 70 }),
      placedAction({ id: 'x2', code: 'launch_job', place: unknownPlace('station_unresolved'), typeName: 'Ishtar', typeId: 12005, quantity: 3 }),
      placedAction({ id: 'x3', code: 'launch_job', place: unknownPlace('moon_unreachable'), typeName: 'Ibis', typeId: 601, quantity: 1 }),
    ])

    expect(wrapper.findAll('[data-testid="todo-place-group"]').map(group => group.attributes('data-place-key'))).toEqual([
      'station:1040000000002',
      'station:60003760',
      'unknown:market_not_chosen',
      'anywhere',
      'unknown:market_structure_unset',
      'unknown:station_unresolved',
      'unknown:moon_unreachable',
    ])
    expect(groupTexts(wrapper)).toEqual([
      expect.stringMatching(/Azbel[\s\S]*Perimeter/),
      expect.stringMatching(/Jita IV - Moon 4 - Caldari Navy Assembly Plant[\s\S]*Jita/),
      expect.stringContaining('Market not chosen'),
      expect.stringContaining('Anywhere'),
      expect.stringContaining('Market structure not set'),
      expect.stringContaining('Step station not resolved'),
      expect.stringMatching(/Unknown place[\s\S]*moon_unreachable/),
    ])
  })

  it('shows the you are here badge on the group where a character is docked only', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    const groups = wrapper.findAll('[data-testid="todo-place-group"]')
    expect(groups.map(group => group.find('[data-testid="todo-here-badge"]').exists())).toEqual([true, false, false, false])
    expect(groups[0].get('[data-testid="todo-here-badge"]').text()).toBe('you are here')
  })

  it('shows no badge when no position is readable', async () => {
    const wrapper = await mountWith([
      placedAction({ id: 'n1', code: 'launch_job', place: AZBEL_PLACE, typeName: 'Ishtar', typeId: 12005, quantity: 2 }),
      SOURCE_ZYDRINE_50_AT_JITA,
    ])

    expect(wrapper.findAll('[data-testid="todo-here-badge"]')).toHaveLength(0)
  })

  it('flags an action whose station access is unknown with an access unknown badge', async () => {
    const wrapper = await mountWith([
      placedAction({ id: 'u1', code: 'launch_job', place: AZBEL_PLACE, typeId: 12005, typeName: 'Ishtar', quantity: 2, access: 'unknown' }),
    ])

    const badges = wrapper.findAll('[data-testid="todo-action"] [data-testid="todo-access-unknown"]')
    expect(badges).toHaveLength(1)
    expect(badges[0].text()).toBe('Access unknown')
  })

  it('shows no access badge on an action with a granted access or no access at all', async () => {
    const wrapper = await mountWith([
      placedAction({ id: 'g1', code: 'launch_job', place: AZBEL_PLACE, typeId: 12005, typeName: 'Ishtar', quantity: 2, access: 'granted' }),
      LAUNCH_RAM_AT_AZBEL,
      SOURCE_TRITANIUM_400_AT_JITA,
    ])

    expect(wrapper.findAll('[data-testid="todo-action"]')).toHaveLength(3)
    expect(wrapper.findAll('[data-testid="todo-access-unknown"]')).toHaveLength(0)
  })

  it('words the access unknown badge in French', async () => {
    const wrapper = await mountWith([
      placedAction({ id: 'u2', code: 'launch_job', place: AZBEL_PLACE, typeId: 12005, typeName: 'Ishtar', quantity: 2, access: 'unknown' }),
    ], 'fr')

    expect(wrapper.get('[data-testid="todo-access-unknown"]').text()).toBe('Accès inconnu')
  })

  it('words each action with its verb, its quantity, its project and a link to the project', async () => {
    const wrapper = await mountWith([
      LAUNCH_RAM_AT_AZBEL,
      placedAction({ id: 'v1', code: 'launch_job', place: AZBEL_PLACE, typeId: null, typeName: null, quantity: null, projectId: IBIS_PROJECT_ID, projectName: null }),
      SOURCE_TRITANIUM_400_AT_JITA,
      placedAction({ id: 'v2', code: 'review_contributions', place: ANYWHERE_PLACE, quantity: 1 }),
      placedAction({ id: 'v3', code: 'review_join_requests', place: ANYWHERE_PLACE, quantity: 2 }),
    ])

    const rows = wrapper.findAll('[data-testid="todo-action"]')
    expect(rows.map(row => row.text())).toEqual([
      expect.stringMatching(/^Launch\s+R\.A\.M\.- Starship Tech\s+5 runs to launch\s+Ishtar batch$/),
      expect.stringMatching(/^Launch\s+runs to define\s+Unnamed project$/),
      expect.stringMatching(/^Buy\s+Tritanium\s+400 to buy\s+Ishtar batch$/),
      expect.stringMatching(/^Review\s+1 contribution to review\s+Ishtar batch$/),
      expect.stringMatching(/^Accept\s+2 join requests\s+Ishtar batch$/),
    ])
    expect(wrapper.findAllComponents(RouterLinkStub)
      .filter(link => link.attributes('data-testid') === 'todo-action')
      .map(link => link.props('to'))).toEqual([
      { name: 'industry-project', params: { id: ISHTAR_PROJECT_ID } },
      { name: 'industry-project', params: { id: IBIS_PROJECT_ID } },
      { name: 'industry-project', params: { id: ISHTAR_PROJECT_ID } },
      { name: 'industry-project', params: { id: ISHTAR_PROJECT_ID } },
      { name: 'industry-project', params: { id: ISHTAR_PROJECT_ID } },
    ])
  })

  it('offers the multibuy on the Jita group only, with its lines and its projects', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    const groups = wrapper.findAll('[data-testid="todo-place-group"]')
    expect(groups.map(group => group.findAll('[data-testid="todo-multibuy"]').map(button => button.text()))).toEqual([
      [],
      ['Multibuy · 3 lines, 2 projects'],
      [],
      [],
    ])
  })

  it('words the multibuy with one project in the singular and gives none for a single purchase', async () => {
    const wrapper = await mountWith([
      SOURCE_TRITANIUM_400_AT_JITA,
      SOURCE_ZYDRINE_50_AT_JITA,
      placedAction({ id: 's1', code: 'source_material', place: AZBEL_PLACE, typeId: 35, typeName: 'Pyerite', quantity: 70 }),
    ])

    const groups = wrapper.findAll('[data-testid="todo-place-group"]')
    expect(groups.map(group => group.findAll('[data-testid="todo-multibuy"]').map(button => button.text()))).toEqual([
      ['Multibuy · 2 lines, 1 project'],
      [],
    ])
  })

  it('copies the market text, then tells that the transaction will be split by project', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)
    const before = wrapper.find('[data-testid="todo-multibuy-feedback"]').exists()

    await wrapper.get('[data-testid="todo-multibuy"]').trigger('click')
    await flushPromises()

    expect(before).toBe(false)
    expect(writeText).toHaveBeenCalledExactlyOnceWith('Tritanium\t520\nZydrine\t50')
    expect(wrapper.get('[data-testid="todo-multibuy-feedback"]').text()).toBe('Copied. The wallet sync will link the transaction to the projects when it can')
    expect(wrapper.find('[data-testid="todo-copy-error"]').exists()).toBe(false)
  })

  it('adds one multibuy button per project, labelled with its name, next to the all projects one', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    const groups = wrapper.findAll('[data-testid="todo-place-group"]')
    expect(groups.map(group => group.findAll('[data-testid="todo-multibuy-project"]').map(button => button.text()))).toEqual([
      [],
      ['Multibuy · Ishtar batch · 2 lines', 'Multibuy · Ibis run · 1 line'],
      [],
      [],
    ])
    expect(wrapper.get('[data-testid="todo-multibuy"]').text()).toBe('Multibuy · 3 lines, 2 projects')
  })

  it('shows no per-project button when a single project buys at the market', async () => {
    const wrapper = await mountWith([
      SOURCE_TRITANIUM_400_AT_JITA,
      SOURCE_ZYDRINE_50_AT_JITA,
    ])

    expect(wrapper.findAll('[data-testid="todo-multibuy"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="todo-multibuy-project"]')).toHaveLength(0)
  })

  it('copies only the lines of the project of the clicked button, and the all projects text stays whole', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    const projectButtons = wrapper.findAll('[data-testid="todo-multibuy-project"]')
    await projectButtons[1].trigger('click')
    await projectButtons[0].trigger('click')
    await wrapper.get('[data-testid="todo-multibuy"]').trigger('click')
    await flushPromises()

    expect(writeText.mock.calls.map(([text]) => text)).toEqual([
      'Tritanium\t120',
      'Tritanium\t400\nZydrine\t50',
      'Tritanium\t520\nZydrine\t50',
    ])
  })

  it('tells after a per-project copy that the transaction will be split by project, with no promise on the split', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    await wrapper.get('[data-testid="todo-multibuy-project"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="todo-multibuy-feedback"]').text()).toBe('Copied. The wallet sync will link the transaction to the projects when it can')
    expect(wrapper.find('[data-testid="todo-copy-error"]').exists()).toBe(false)
  })

  it('shows an error and never Copied when the browser refuses a per-project copy', async () => {
    writeText.mockRejectedValue(new Error('denied'))
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    await wrapper.get('[data-testid="todo-multibuy-project"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="todo-multibuy-feedback"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="todo-copy-error"]').text()).not.toBe('')
  })

  it('copies the quantity left to buy in the per-project text once the others are subtracted', async () => {
    const wrapper = await mountWith([
      BUY_TRITANIUM_250_WITH_OTHERS,
      SOURCE_ZYDRINE_50_AT_JITA,
      placedAction({ id: 'p1', code: 'source_material', place: JITA_PLACE, typeId: 35, typeName: 'Pyerite', quantity: 70, projectId: IBIS_PROJECT_ID, projectName: 'Ibis run' }),
    ])

    await wrapper.findAll('[data-testid="todo-multibuy-project"]')[0].trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledExactlyOnceWith('Tritanium\t250\nZydrine\t50')
  })

  it('words a project without name in the per-project button', async () => {
    const wrapper = await mountWith([
      SOURCE_TRITANIUM_400_AT_JITA,
      placedAction({ id: 'u1', code: 'source_material', place: JITA_PLACE, typeId: 35, typeName: 'Pyerite', quantity: 70, projectId: IBIS_PROJECT_ID, projectName: null }),
    ])

    expect(wrapper.findAll('[data-testid="todo-multibuy-project"]').map(button => button.text())).toEqual([
      'Multibuy · Ishtar batch · 1 line',
      'Multibuy · Unnamed project · 1 line',
    ])
  })

  it('words the per-project buttons in French', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS, 'fr')

    expect(wrapper.findAll('[data-testid="todo-multibuy-project"]').map(button => button.text())).toEqual([
      'Multibuy · Ishtar batch · 2 lignes',
      'Multibuy · Ibis run · 1 ligne',
    ])
  })

  it('tells under an action who already took part of it and how much, in the order of the server', async () => {
    const wrapper = await mountWith([BUY_TRITANIUM_250_WITH_OTHERS, SOURCE_ZYDRINE_50_AT_JITA])

    const lines = wrapper.findAll('[data-testid="todo-reserved-by-others"]')
    expect(lines).toHaveLength(1)
    const text = squashed(lines[0].text())
    expect(text).toContain('Mira Kessel 150')
    expect(text).toContain('Kaelen Dray 100')
    expect(text.indexOf('Mira Kessel')).toBeLessThan(text.indexOf('Kaelen Dray'))
  })

  it('words the already taken line in French', async () => {
    const wrapper = await mountWith([BUY_TRITANIUM_250_WITH_OTHERS], 'fr')

    expect(squashed(wrapper.get('[data-testid="todo-reserved-by-others"]').text())).toBe('Déjà pris : Mira Kessel 150, Kaelen Dray 100')
  })

  it('writes a translated name, never null, for a member without character', async () => {
    const wrapper = await mountWith([placedAction({ ...SOURCE_ZYDRINE_50_AT_JITA, quantity: 20, reservedByOthers: [{ characterName: null, quantity: 30 }] })])

    const text = squashed(wrapper.get('[data-testid="todo-reserved-by-others"]').text())
    expect(text).toBe('Already taken: Unknown member 30')
  })

  it('shows no already taken line when nobody else reserved anything', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    expect(wrapper.find('[data-testid="todo-reserved-by-others"]').exists()).toBe(false)
  })

  it('copies in the multibuy the quantity left to buy once the others are subtracted', async () => {
    const wrapper = await mountWith([BUY_TRITANIUM_250_WITH_OTHERS, SOURCE_ZYDRINE_50_AT_JITA])

    await wrapper.get('[data-testid="todo-multibuy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledExactlyOnceWith('Tritanium\t250\nZydrine\t50')
  })

  it('shows an error and never Copied when the browser refuses the copy', async () => {
    writeText.mockRejectedValue(new Error('denied'))
    const wrapper = await mountWith(EXAMPLE_ACTIONS)

    await wrapper.get('[data-testid="todo-multibuy"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="todo-multibuy-feedback"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="todo-copy-error"]').text()).not.toBe('')
    expect(wrapper.text()).not.toContain('Copied')
  })

  it('tells how many purchase lines without a name are left out of the multibuy', async () => {
    const wrapper = await mountWith([
      ...EXAMPLE_ACTIONS,
      placedAction({ id: 'k1', code: 'source_material', place: JITA_PLACE, typeId: 38, typeName: null, quantity: 15 }),
    ])

    expect(wrapper.get('[data-testid="todo-multibuy-skipped"]').text()).toBe('1 line without a name or quantity not included')
    expect(wrapper.get('[data-testid="todo-multibuy"]').text()).toBe('Multibuy · 3 lines, 2 projects')
  })

  it('names a station without a name explicitly instead of leaving its header empty', async () => {
    const wrapper = await mountWith([
      placedAction({ id: 'u1', code: 'launch_job', place: { ...AZBEL_PLACE, name: null, solarSystemName: null }, typeName: 'Ishtar', typeId: 12005, quantity: 2 }),
    ])

    expect(groupTexts(wrapper)).toEqual(['Unnamed station'])
  })

  it('says there is nothing to do instead of an empty list', async () => {
    const wrapper = await mountWith([])

    expect(wrapper.get('[data-testid="todo-empty"]').text()).toBe('Nothing to do')
    expect(wrapper.findAll('[data-testid="todo-place-group"]')).toHaveLength(0)
  })

  it('shows the error of the store in a banner without groups nor empty message', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Place actions are down'))
    const wrapper = await mountView()

    expect(wrapper.getComponent(ErrorBanner).props('message')).toBe('Place actions are down')
    expect(wrapper.findAll('[data-testid="todo-place-group"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="todo-empty"]').exists()).toBe(false)
  })

  it('words the page in French', async () => {
    const wrapper = await mountWith(EXAMPLE_ACTIONS, 'fr')

    expect(groupTexts(wrapper).some(text => text.includes('Partout'))).toBe(true)
    expect(wrapper.get('[data-testid="todo-here-badge"]').text()).toBe('vous y êtes')
    expect(wrapper.get('[data-testid="todo-multibuy"]').text()).toBe('Multibuy · 3 lignes, 2 projets')
  })

  it('words the empty page and the copy in French', async () => {
    const empty = await mountWith([], 'fr')
    const emptyText = empty.get('[data-testid="todo-empty"]').text()
    empty.unmount()
    const wrapper = await mountWith(EXAMPLE_ACTIONS, 'fr')

    await wrapper.get('[data-testid="todo-multibuy"]').trigger('click')
    await flushPromises()

    expect(emptyText).toBe('Rien à faire')
    expect(wrapper.get('[data-testid="todo-multibuy-feedback"]').text()).toBe('Copié. La synchro du wallet rattachera la transaction aux projets quand elle le pourra')
  })
})
