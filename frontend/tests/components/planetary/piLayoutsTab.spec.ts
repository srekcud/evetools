import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiLayoutsTab from '@/components/planetary/PiLayoutsTab.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest, ValidationError } from '@/services/api'
import type { ColonyLayout } from '@/stores/planetaryLayouts'
import { COOLANT_LAYOUT, ROBOTICS_LAYOUT, VALID_GAME_TEXT } from '../../planetaryLayoutsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const LAYOUTS_ENDPOINT = '/planetary/layouts'
const IMPORTED_LAYOUT: ColonyLayout = { ...COOLANT_LAYOUT, id: 'layout-imported', name: 'Imported colony' }

async function mountTab(layouts: ColonyLayout[], onPost: () => unknown = () => IMPORTED_LAYOUT) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === LAYOUTS_ENDPOINT && method === 'GET') return layouts
    if (endpoint === LAYOUTS_ENDPOINT && method === 'POST') return onPost()
    return null
  }) as never)
  const wrapper = mount(PiLayoutsTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

type TabWrapper = Awaited<ReturnType<typeof mountTab>>

function cards(wrapper: TabWrapper) {
  return wrapper.findAll('[data-testid="layout-card"]')
}

function cardOf(wrapper: TabWrapper, layoutId: string) {
  return wrapper.get(`[data-testid="layout-card"][data-layout-id="${layoutId}"]`)
}

function digitsOf(text: string) {
  return text.replace(/\D/g, '')
}

describe('PiLayoutsTab my layouts', () => {
  it('says explicitly that there is no layout yet and draws no card', async () => {
    const wrapper = await mountTab([])

    expect(wrapper.get('[data-testid="layouts-empty"]').text()).not.toBe('')
    expect(cards(wrapper)).toHaveLength(0)
  })

  it('draws one card per layout with its name, planet, command center level, pin count, load and products', async () => {
    const wrapper = await mountTab([COOLANT_LAYOUT, ROBOTICS_LAYOUT])

    expect(cards(wrapper).map(card => card.attributes('data-layout-id'))).toEqual(['layout-coolant', 'layout-robotics'])
    expect(wrapper.find('[data-testid="layouts-empty"]').exists()).toBe(false)
    const coolant = cardOf(wrapper, 'layout-coolant')
    expect(coolant.get('[data-testid="layout-name"]').text()).toBe('Coolant starter')
    expect(coolant.get('[data-testid="layout-planet"]').text()).toContain('Barren')
    expect(digitsOf(coolant.get('[data-testid="layout-level"]').text())).toBe('5')
    expect(digitsOf(coolant.get('[data-testid="layout-pins"]').text())).toBe('3')
    expect(digitsOf(coolant.get('[data-testid="layout-cpu"]').text())).toBe('4600')
    expect(digitsOf(coolant.get('[data-testid="layout-powergrid"]').text())).toBe('2100')
    expect(coolant.get('[data-testid="layout-products"]').text()).toContain('Coolant')
    const robotics = cardOf(wrapper, 'layout-robotics')
    expect(robotics.get('[data-testid="layout-name"]').text()).toBe('Robotics line')
    expect(robotics.get('[data-testid="layout-planet"]').text()).toContain('Lava')
    expect(digitsOf(robotics.get('[data-testid="layout-level"]').text())).toBe('3')
    expect(digitsOf(robotics.get('[data-testid="layout-pins"]').text())).toBe('7')
    expect(robotics.get('[data-testid="layout-products"]').text()).toContain('Robotics')
    expect(robotics.get('[data-testid="layout-products"]').text()).toContain('Rocket Fuel')
  })

  it('shows CPU and PG as unknown, never as 0, when the load of a layout is null', async () => {
    const wrapper = await mountTab([ROBOTICS_LAYOUT])
    const robotics = cardOf(wrapper, 'layout-robotics')

    expect(robotics.get('[data-testid="layout-cpu"]').text()).not.toBe('')
    expect(digitsOf(robotics.get('[data-testid="layout-cpu"]').text())).toBe('')
    expect(robotics.get('[data-testid="layout-powergrid"]').text()).not.toBe('')
    expect(digitsOf(robotics.get('[data-testid="layout-powergrid"]').text())).toBe('')
  })

  it('copies the game template with the layout name as comment to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const wrapper = await mountTab([COOLANT_LAYOUT, ROBOTICS_LAYOUT])

    await cardOf(wrapper, 'layout-coolant').get('[data-testid="layout-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    const written = String(writeText.mock.calls[0][0])
    const expected = { ...COOLANT_LAYOUT.gameTemplate, Cmt: 'Coolant starter' }
    expect(JSON.parse(written)).toEqual(expected)
    expect(Object.keys(JSON.parse(written))).toEqual(Object.keys(COOLANT_LAYOUT.gameTemplate))
  })

  it('downloads the game template with the layout name as comment in a file named after the layout', async () => {
    const downloads: string[] = []
    const blobs: Blob[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      downloads.push(this.download)
    })
    URL.createObjectURL = vi.fn((blob: Blob | MediaSource) => {
      blobs.push(blob as Blob)
      return 'blob:colony-layout'
    })
    URL.revokeObjectURL = vi.fn()
    const wrapper = await mountTab([COOLANT_LAYOUT, ROBOTICS_LAYOUT])

    await cardOf(wrapper, 'layout-robotics').get('[data-testid="layout-download"]').trigger('click')
    await flushPromises()

    expect(downloads).toEqual(['Robotics line.json'])
    expect(blobs).toHaveLength(1)
    expect(JSON.parse(await blobs[0].text())).toEqual({ ...ROBOTICS_LAYOUT.gameTemplate, Cmt: 'Robotics line' })
  })
})

describe('PiLayoutsTab import', () => {
  it('lists every error after an invalid import and leaves the cards as they were', async () => {
    const wrapper = await mountTab([COOLANT_LAYOUT], () => {
      throw new ValidationError({
        '@type': 'ConstraintViolationList',
        status: 422,
        violations: [
          { propertyPath: 'P[0].T', message: 'unknown_installation' },
          { propertyPath: 'CmdCtrLv', message: 'out_of_range' },
        ],
        description: '',
        detail: '',
      })
    })

    await wrapper.get('[data-testid="layout-import-text"]').setValue('{"CmdCtrLv":6}')
    await wrapper.get('[data-testid="layout-import-submit"]').trigger('click')
    await flushPromises()

    const errors = wrapper.findAll('[data-testid="layout-import-error"]')
    expect(errors.map(error => [error.attributes('data-path'), error.attributes('data-code')])).toEqual([
      ['P[0].T', 'unknown_installation'],
      ['CmdCtrLv', 'out_of_range'],
    ])
    expect(errors.every(error => error.text() !== '')).toBe(true)
    expect(cards(wrapper).map(card => card.attributes('data-layout-id'))).toEqual(['layout-coolant'])
  })

  it('sends the pasted text and the name, draws the new card first and shows no error after a valid import', async () => {
    const wrapper = await mountTab([COOLANT_LAYOUT])

    await wrapper.get('[data-testid="layout-import-text"]').setValue(VALID_GAME_TEXT)
    await wrapper.get('[data-testid="layout-import-name"]').setValue('Imported colony')
    await wrapper.get('[data-testid="layout-import-submit"]').trigger('click')
    await flushPromises()

    const posted = vi.mocked(apiRequest).mock.calls.filter(call => (call[1] as RequestInit | undefined)?.method === 'POST')
    expect(posted.map(call => [call[0], JSON.parse(String((call[1] as RequestInit).body))])).toEqual([
      [LAYOUTS_ENDPOINT, { template: VALID_GAME_TEXT, name: 'Imported colony' }],
    ])
    expect(cards(wrapper).map(card => card.attributes('data-layout-id'))).toEqual(['layout-imported', 'layout-coolant'])
    expect(wrapper.findAll('[data-testid="layout-import-error"]')).toHaveLength(0)
  })
})

describe('PiLayoutsTab community collections', () => {
  it('links the five external collections in a new tab with noopener and noreferrer', async () => {
    const wrapper = await mountTab([])

    const links = wrapper.findAll('[data-testid="layout-community-link"]')
    const hrefs = links.map(link => link.attributes('href') ?? '')
    expect(links).toHaveLength(5)
    expect(new Set(hrefs).size).toBe(5)
    expect(hrefs.every(href => href.startsWith('https://'))).toBe(true)
    expect(hrefs.some(href => href.includes('github.com/DalShooth/EVE_PI_Templates'))).toBe(true)
    expect(hrefs.some(href => href.includes('github.com/MetalGear32/evepi'))).toBe(true)
    expect(hrefs.some(href => href.includes('github.com/TheLegi0n-NBI/PlayingWithPP'))).toBe(true)
    expect(hrefs.some(href => href.includes('eveuniversity.org'))).toBe(true)
    for (const link of links) {
      expect(link.attributes('target')).toBe('_blank')
      expect((link.attributes('rel') ?? '').split(/\s+/).sort()).toEqual(['noopener', 'noreferrer'])
    }
  })
})
