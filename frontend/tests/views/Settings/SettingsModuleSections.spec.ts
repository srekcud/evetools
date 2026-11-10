import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import MiningValuationControls from '@/components/ledger/MiningValuationControls.vue'
import ReprocessingProfilePanel from '@/components/ledger/ReprocessingProfilePanel.vue'
import TypeConfigDialog from '@/components/ledger/pve/TypeConfigDialog.vue'
import NotificationSettings from '@/components/notifications/NotificationSettings.vue'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import FeesConfig from '@/components/industry/FeesConfig.vue'
import SkillsConfig from '@/components/industry/SkillsConfig.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { callsTo, mountSettingsAt, stubBackend, type BackendCall } from './settingsHarness'

vi.mock('@/composables/usePushNotifications', () => ({
  usePushNotifications: () => ({
    isSupported: { value: true },
    permission: { value: 'granted' },
    requestPermission: vi.fn(),
    subscribe: vi.fn(),
  }),
}))

const SETTINGS_URL = '/api/me/settings'
const VALUATION_PROFILE_URL = '/api/mining/valuation-profile'
const LEDGER_SETTINGS_URL = '/api/ledger/settings'
const PERIMETER_STRUCTURE = { id: 1_035_466_617_946, name: 'Perimeter - Tranquility Trading Tower' }
const JITA_STRUCTURE = { id: 60_003_760, name: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant' }
const KEEPSTAR_RESULT = { locationId: 1_040_000_000_001, locationName: 'Keepstar Alpha - Amamake', solarSystemName: 'Amamake' }
const ENTERED_MAX_JOB_DURATION_DAYS = 3.5
const MINIMUM_MAX_JOB_DURATION_DAYS = 0.5

const miningProfile = {
  market: 'jita',
  side: 'buy',
  form: 'compressed',
  structureId: null,
  structureName: null,
  exportTax: 0,
  persisted: true,
}

const ledgerSettings = {
  corpProjectAccounting: 'pve',
  autoSyncEnabled: true,
  lastMiningSyncAt: null,
  excludedTypeIds: [],
  defaultSoldTypeIds: [],
  updatedAt: '2026-11-09T10:00:00+00:00',
}

const notificationPreferences = [
  { category: 'planetary', enabled: true, thresholdMinutes: 360, pushEnabled: false },
  { category: 'price', enabled: true, thresholdMinutes: null, pushEnabled: false },
]

function buttonWithText(wrapper: VueWrapper, text: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === text)
  expect(button, `button ${text}`).toBeDefined()
  return button!
}

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('Settings market section', () => {
  it('lists the market structures of the user and removes one with a PATCH of the remaining list', async () => {
    const calls = stubBackend({
      [`GET ${SETTINGS_URL}`]: { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [PERIMETER_STRUCTURE, JITA_STRUCTURE] },
      [`PATCH ${SETTINGS_URL}`]: {},
    })
    const { wrapper } = await mountSettingsAt('/settings/market')
    expect(wrapper.text()).toContain(PERIMETER_STRUCTURE.name)
    expect(wrapper.text()).toContain(JITA_STRUCTURE.name)

    await wrapper.findAll('button[aria-label="Delete"]')[0].trigger('click')
    await flushPromises()

    expect(callsTo(calls, 'PATCH', SETTINGS_URL).map(call => call.body)).toEqual([{ marketStructures: [JITA_STRUCTURE] }])
    expect(wrapper.text()).not.toContain(PERIMETER_STRUCTURE.name)
  })

  it('searches a structure and adds the chosen one with a PATCH of the whole list', async () => {
    const calls = stubBackend({
      [`GET ${SETTINGS_URL}`]: { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [PERIMETER_STRUCTURE] },
      [`PATCH ${SETTINGS_URL}`]: {},
      'GET /api/industry/search-structure?q=Keepstar': { structures: [KEEPSTAR_RESULT], incomplete: false },
    })
    const { wrapper } = await mountSettingsAt('/settings/market')

    await wrapper.get('input[aria-label="Search a structure..."]').setValue('Keepstar')
    await vi.waitFor(() => expect(wrapper.text()).toContain(KEEPSTAR_RESULT.locationName), { timeout: 3000 })
    await wrapper.findAll('button').find(button => button.text().includes(KEEPSTAR_RESULT.locationName))?.trigger('mousedown')
    await flushPromises()

    expect(callsTo(calls, 'PATCH', SETTINGS_URL).map(call => call.body)).toEqual([
      { marketStructures: [PERIMETER_STRUCTURE, { id: KEEPSTAR_RESULT.locationId, name: KEEPSTAR_RESULT.locationName }] },
    ])
  })
})

describe('Settings industry section', () => {
  it('shows the max job duration and the four configurations, and writes the duration to the industry projects store', async () => {
    stubBackend({})
    const { wrapper } = await mountSettingsAt('/settings/industry', {
      stubs: { FeesConfig: true, SkillsConfig: true, StructureConfig: true, BlacklistConfig: true },
    })
    const label = wrapper.findAll('label').find(candidate => candidate.text() === 'Max job duration')
    expect(label, 'label Max job duration').toBeDefined()
    const input = wrapper.get(`[id="${label!.attributes('for')}"]`)
    const store = useProjectsStore() as unknown as { defaultMaxJobDurationDays: number }
    const shownConfigurations = [wrapper.findComponent(FeesConfig).exists()]
    for (const [header, configuration] of [['Skills', SkillsConfig], ['Structures', StructureConfig], ['Blacklist', BlacklistConfig]] as const) {
      await buttonWithText(wrapper, header).trigger('click')
      shownConfigurations.push(wrapper.findComponent(configuration).exists())
    }

    await input.setValue(String(ENTERED_MAX_JOB_DURATION_DAYS))
    await input.trigger('change')
    const afterEntering = store.defaultMaxJobDurationDays
    await input.setValue('0.1')
    await input.trigger('change')

    expect((input.element as HTMLInputElement).value).toBe('0.5')
    expect(shownConfigurations).toEqual([true, true, true, true])
    expect([afterEntering, store.defaultMaxJobDurationDays]).toEqual([ENTERED_MAX_JOB_DURATION_DAYS, MINIMUM_MAX_JOB_DURATION_DAYS])
  })
})

describe('Settings mining section', () => {
  it('shows the valuation controls and the reprocessing profile, and writes the market and side to the server profile', async () => {
    const calls = stubBackend({
      [`GET ${VALUATION_PROFILE_URL}`]: miningProfile,
      [`PATCH ${VALUATION_PROFILE_URL}`]: (call: BackendCall) => ({ ...miningProfile, ...(call.body as Record<string, unknown>) }),
    })
    const { wrapper } = await mountSettingsAt('/settings/mining')

    await buttonWithText(wrapper, 'Jita sell').trigger('click')
    await flushPromises()

    expect(wrapper.findComponent(MiningValuationControls).exists()).toBe(true)
    expect(wrapper.findComponent(ReprocessingProfilePanel).exists()).toBe(true)
    expect(callsTo(calls, 'PATCH', VALUATION_PROFILE_URL).map(call => call.body)).toEqual([{ market: 'jita', side: 'sell' }])
  })
})

describe('Settings pve section', () => {
  it('shows the corporation project accounting and writes the choice with a PATCH of the ledger settings', async () => {
    const calls = stubBackend({
      [`GET ${LEDGER_SETTINGS_URL}`]: ledgerSettings,
      [`PATCH ${LEDGER_SETTINGS_URL}`]: { ...ledgerSettings, corpProjectAccounting: 'mining' },
    })
    const { wrapper } = await mountSettingsAt('/settings/pve')

    expect(wrapper.get<HTMLInputElement>('input[name="corpProjectAccounting"][value="pve"]').element.checked).toBe(true)
    await wrapper.get('input[name="corpProjectAccounting"][value="mining"]').trigger('change')
    await flushPromises()

    expect(callsTo(calls, 'PATCH', LEDGER_SETTINGS_URL).map(call => call.body)).toEqual([{ corpProjectAccounting: 'mining' }])
  })

  it('opens the ammo configuration dialog from a button of the section', async () => {
    stubBackend({ [`GET ${LEDGER_SETTINGS_URL}`]: ledgerSettings })
    const { wrapper } = await mountSettingsAt('/settings/pve')
    const visibleBefore = wrapper.findAllComponents(TypeConfigDialog).filter(dialog => dialog.props('visible')).length

    await wrapper.get('button[title="Configure ammo"]').trigger('click')

    expect(visibleBefore).toBe(0)
    expect(wrapper.findAllComponents(TypeConfigDialog).filter(dialog => dialog.props('visible')).map(dialog => dialog.props('title'))).toEqual(['Ammo configuration'])
  })
})

describe('Settings notifications section', () => {
  it('shows the notification preferences inline, with no modal and no close button', async () => {
    stubBackend({ 'GET /api/me/notification-preferences': { preferences: notificationPreferences } })
    const { wrapper } = await mountSettingsAt('/settings/notifications')

    expect(wrapper.findComponent(NotificationSettings).exists()).toBe(true)
    expect(wrapper.findComponent(NotificationSettings).props('inline')).toBe(true)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(wrapper.find('button[aria-label="Close"]').exists()).toBe(false)
    expect((wrapper.get('input[type="number"]').element as HTMLInputElement).value).toBe('360')
  })
})
