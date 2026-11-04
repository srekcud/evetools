import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { useMiningOperationStore, type MiningSeason } from '@/stores/miningOperation'
import MiningSeasonPanel from '@/views/MiningOperation/MiningSeasonPanel.vue'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SEASON_PATH = '/mining-operations/season'
const LONG_PLAYER_NAME = 'Cyra Lune-Valdemar de la Ceinture Extérieure des Anomalies Spodumain'

function seasonWithAnUnknownOperation(overrides: Partial<MiningSeason> = {}): MiningSeason {
  return {
    period: '30d',
    operationsCount: 3,
    unknownValueOperationsCount: 1,
    knownValue: 150_000_000,
    knownOperationMinutes: 180,
    playedMinutes: 450,
    iskPerHour: 50_000_000,
    players: [
      { name: 'Aria Solberg', operationsCount: 3, minutes: 270, minedValue: 100_000_000, hasUnknownValue: true },
      { name: 'Brann Holt', operationsCount: 1, minutes: 90, minedValue: 50_000_000, hasUnknownValue: false },
      { name: 'Cyra Lune', operationsCount: 1, minutes: 90, minedValue: null, hasUnknownValue: true },
    ],
    ...overrides,
  }
}

function seasonOfKnownOperations(): MiningSeason {
  return seasonWithAnUnknownOperation({ operationsCount: 2, unknownValueOperationsCount: 0, playedMinutes: 270, players: [
    { name: 'Aria Solberg', operationsCount: 2, minutes: 180, minedValue: 100_000_000, hasUnknownValue: false },
    { name: 'Brann Holt', operationsCount: 1, minutes: 90, minedValue: 50_000_000, hasUnknownValue: false },
  ] })
}

function seasonOfOneUnknownOperation(): MiningSeason {
  return {
    period: '30d',
    operationsCount: 1,
    unknownValueOperationsCount: 1,
    knownValue: null,
    knownOperationMinutes: 0,
    playedMinutes: 90,
    iskPerHour: null,
    players: [{ name: 'Cyra Lune', operationsCount: 1, minutes: 90, minedValue: null, hasUnknownValue: true }],
  }
}

function emptySeason(): MiningSeason {
  return { period: '30d', operationsCount: 0, unknownValueOperationsCount: 0, knownValue: null, knownOperationMinutes: 0, playedMinutes: 0, iskPerHour: null, players: [] }
}

function mountPanel(season: MiningSeason) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  vi.mocked(apiRequest).mockResolvedValue(season as never)
  useMiningOperationStore().season = season
  const wrapper = mount(MiningSeasonPanel, { global: { plugins: [i18n] } })
  return { wrapper, t: i18n.global.t }
}

function card(wrapper: VueWrapper, name: string): string {
  const element = wrapper.find(`[data-testid="season-${name}"]`)
  expect(element.exists(), `season-${name}`).toBe(true)
  return element.text()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningSeasonPanel', () => {
  it('shows the four indicators of the season with its operations, known value, mined hours and ISK per hour', async () => {
    const { wrapper, t } = mountPanel(seasonWithAnUnknownOperation())
    await flushPromises()

    const operations = card(wrapper, 'operations')
    const knownValue = card(wrapper, 'known-value')
    const minedHours = card(wrapper, 'mined-hours')
    const iskPerHour = card(wrapper, 'isk-per-hour')

    expect([
      operations.includes('3') && operations.includes(t('miningOperation.season.kpi.withoutPrice', { count: 1 })),
      knownValue.includes('150,000,000'),
      minedHours.includes('7 h 30 min') && minedHours.includes(t('miningOperation.season.kpi.players', { count: 3 })),
      iskPerHour.includes('50,000,000'),
    ]).toEqual([true, true, true, true])
  })

  it('says unknown instead of 0 for the known value and the ISK per hour of a season without any known operation', async () => {
    const { wrapper, t } = mountPanel(seasonOfOneUnknownOperation())
    await flushPromises()

    const unknown = t('miningOperation.quantities.unknown')
    expect([
      card(wrapper, 'known-value').includes(unknown),
      /\d/.test(card(wrapper, 'known-value')),
      card(wrapper, 'isk-per-hour').includes(unknown),
      /\d/.test(card(wrapper, 'isk-per-hour')),
    ]).toEqual([true, false, true, false])
  })

  it('warns that the value of the season is unknown only when an operation has no known value', async () => {
    const withUnknown = mountPanel(seasonWithAnUnknownOperation())
    await flushPromises()
    const bannerWithUnknown = withUnknown.wrapper.find('[data-testid="season-unknown-banner"]')
    const bannerTitle = withUnknown.t('miningOperation.season.unknownBanner.title')
    const bannerText = bannerWithUnknown.exists() ? bannerWithUnknown.text() : ''
    withUnknown.wrapper.unmount()

    const known = mountPanel(seasonOfKnownOperations())
    await flushPromises()

    expect([bannerWithUnknown.exists(), bannerText.includes(bannerTitle), known.wrapper.find('[data-testid="season-unknown-banner"]').exists()]).toEqual([true, true, false])
  })

  it('lists the players with their mined hours, their known value and a mention when part of their value is unknown', async () => {
    const season = seasonWithAnUnknownOperation()
    season.players[2] = { ...season.players[2], name: LONG_PLAYER_NAME }
    const { wrapper, t } = mountPanel(season)
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="season-player-row"]').map(row => row.text())
    const plusUnknown = t('miningOperation.season.players.plusUnknown')
    const longNameCell = wrapper.findAll('[data-testid="season-player-name"]').find(name => name.text() === LONG_PLAYER_NAME)
    expect({
      names: rows.map(row => row.split(/\s/)[0]),
      aria: [rows[0]?.includes('4 h 30 min'), rows[0]?.includes('100,000,000'), rows[0]?.includes(plusUnknown)],
      brann: [rows[1]?.includes('1 h 30 min'), rows[1]?.includes('50,000,000'), rows[1]?.includes(plusUnknown)],
      cyra: [rows[2]?.includes(t('miningOperation.quantities.unknown')), rows[2]?.includes(plusUnknown)],
      longNameWraps: longNameCell?.classes().includes('[overflow-wrap:anywhere]'),
    }).toEqual({
      names: ['Aria', 'Brann', 'Cyra'],
      aria: [true, true, true],
      brann: [true, true, false],
      cyra: [true, true],
      longNameWraps: true,
    })
  })

  it('shows the empty state of a season without operation and no player', async () => {
    const { wrapper, t } = mountPanel(emptySeason())
    await flushPromises()

    expect([wrapper.text().includes(t('miningOperation.season.empty')), wrapper.findAll('[data-testid="season-player-row"]').length]).toEqual([true, 0])
  })

  it('reloads the season of the period chosen in the selector', async () => {
    const { wrapper, t } = mountPanel(seasonWithAnUnknownOperation())
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    const ninetyDays = wrapper.findAll('button').find(button => button.text() === t('miningOperation.season.periods.90d'))
    expect(ninetyDays, '90 days button').toBeDefined()
    await ninetyDays?.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls).toContainEqual([`${SEASON_PATH}?period=90d`])
  })
})
