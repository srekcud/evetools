import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { SETTINGS_SECTIONS, mountSettingsAt, stubBackend } from './settingsHarness'

const PERIMETER_STRUCTURE_ID = 1_035_466_617_946
const PERIMETER_STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower'
const MARKET_SETTINGS = {
  preferredMarketStructureId: null,
  preferredMarketStructureName: null,
  marketStructures: [{ id: PERIMETER_STRUCTURE_ID, name: PERIMETER_STRUCTURE_NAME }],
}
const LEDGER_SETTINGS = {
  corpProjectAccounting: 'pve',
  autoSyncEnabled: true,
  lastMiningSyncAt: null,
  excludedTypeIds: [],
  defaultSoldTypeIds: [],
  updatedAt: '2026-11-09T10:00:00+00:00',
}

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('Settings page', () => {
  it('shows the title, the Account tab bar with Settings current and one link per section, the section of the URL being current', async () => {
    stubBackend({})
    const { wrapper } = await mountSettingsAt('/settings/industry')

    const sectionLinks = wrapper.findAll('a[href^="/settings/"]')

    expect(wrapper.get('h1').text()).toBe('Settings')
    expect(wrapper.findAll('nav[aria-label="Account"] a[aria-current="page"]').map(link => link.text())).toEqual(['Settings'])
    expect(sectionLinks.map(link => link.attributes('href'))).toEqual(SETTINGS_SECTIONS.map(section => `/settings/${section}`))
    expect(sectionLinks.filter(link => link.attributes('aria-current') === 'page').map(link => link.attributes('href'))).toEqual(['/settings/industry'])
  })

  it('shows the section named in the URL and follows the URL when it changes', async () => {
    stubBackend({
      'GET /api/me/settings': MARKET_SETTINGS,
      'GET /api/ledger/settings': LEDGER_SETTINGS,
    })
    const { wrapper, router } = await mountSettingsAt('/settings/market')
    const marketShown = wrapper.text().includes(PERIMETER_STRUCTURE_NAME)
    const ledgerRadioShownOnMarket = wrapper.find('input[name="corpProjectAccounting"]').exists()

    await router.push('/settings/pve')
    await flushPromises()

    expect({ marketShown, ledgerRadioShownOnMarket }).toEqual({ marketShown: true, ledgerRadioShownOnMarket: false })
    expect(wrapper.text()).not.toContain(PERIMETER_STRUCTURE_NAME)
    expect(wrapper.find('input[name="corpProjectAccounting"]').exists()).toBe(true)
    expect(wrapper.findAll('a[aria-current="page"][href^="/settings/"]').map(link => link.attributes('href'))).toEqual(['/settings/pve'])
  })
})
