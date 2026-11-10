import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { useMiningOperationStore, type MiningSeason, type MiningSeasonShip, type MiningSeasonSystemSites } from '@/stores/miningOperation'
import MiningSeasonPanel from '@/views/MiningOperation/MiningSeasonPanel.vue'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SEASON_PATH = '/mining-operations/season'
const LONG_PLAYER_NAME = 'Cyra Lune-Valdemar de la Ceinture Extérieure des Anomalies Spodumain'
const LONG_SHIP_NAME = 'Hulk Exhumer-Valdemar Edition Prototype de la Ceinture Extérieure'
const HULK_TYPE_ID = 22544
const SKIFF_TYPE_ID = 22546
const ORCA_TYPE_ID = 28606
const ALDRANETTE_ID = 30_003_504
const AMARR_ID = 30_002_187
const SYSTEM_OUTSIDE_THE_SDE_ID = 30_999_999
const LONG_SITE_LABEL = 'Anomalie Spodumain-Valdemar de la Ceinture Extérieure des Mineurs'

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
    ships: [],
    unknownShipCharactersCount: 0,
    sitesBySystem: [],
    sitesWithoutSystemCount: 0,
    ...overrides,
  }
}

function shipsOfTheSeason(): MiningSeasonShip[] {
  return [
    { shipTypeId: HULK_TYPE_ID, shipName: LONG_SHIP_NAME, charactersCount: 2, minutes: 240, minedValue: 120_000_000, iskPerHour: 30_000_000, isBooster: false },
    { shipTypeId: SKIFF_TYPE_ID, shipName: 'Skiff', charactersCount: 1, minutes: 90, minedValue: 20_000_000, iskPerHour: 13_333_333, isBooster: false },
    { shipTypeId: ORCA_TYPE_ID, shipName: 'Orca', charactersCount: 1, minutes: 120, minedValue: null, iskPerHour: null, isBooster: true },
  ]
}

function sitesOfTheSeason(): MiningSeasonSystemSites[] {
  return [
    { solarSystemId: ALDRANETTE_ID, solarSystemName: 'Aldranette', sitesCount: 3, bestSite: { label: LONG_SITE_LABEL, iskPerHour: 1_800_000, observedSeconds: 3_600 }, unknownSites: [] },
    { solarSystemId: AMARR_ID, solarSystemName: 'Amarr', sitesCount: 2, bestSite: { label: 'Belt Amarr', iskPerHour: 900_000, observedSeconds: 5_400 }, unknownSites: [] },
  ]
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
    ships: [],
    unknownShipCharactersCount: 0,
    sitesBySystem: [],
    sitesWithoutSystemCount: 0,
  }
}

function emptySeason(): MiningSeason {
  return { period: '30d', operationsCount: 0, unknownValueOperationsCount: 0, knownValue: null, knownOperationMinutes: 0, playedMinutes: 0, iskPerHour: null, players: [], ships: [], unknownShipCharactersCount: 0, sitesBySystem: [], sitesWithoutSystemCount: 0 }
}

function mountPanel(season: MiningSeason, locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  vi.mocked(apiRequest).mockResolvedValue(season as never)
  useMiningOperationStore().season = season
  const wrapper = mount(MiningSeasonPanel, { global: { plugins: [i18n] } })
  return { wrapper, t: i18n.global.t }
}

function textOfFirst(wrapper: VueWrapper, testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
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

  it('lists the ISK per hour of each ship with its characters, hours, value and a bar proportional to the best ship', async () => {
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ ships: shipsOfTheSeason() }))
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="season-ship-row"]')
    const names = wrapper.findAll('[data-testid="season-ship-name"]')
    const bars = wrapper.findAll('[data-testid="season-ship-bar"]')
    expect({
      names: names.map(name => name.text()),
      hulk: [rows[0]?.text().includes('4 h 00 min'), rows[0]?.text().includes('120,000,000'), rows[0]?.text().includes('30,000,000')],
      skiff: [rows[1]?.text().includes('1 h 30 min'), rows[1]?.text().includes('20,000,000'), rows[1]?.text().includes('13,333,333')],
      barWidths: bars.map(bar => bar.attributes('style')),
      longNameWraps: names[0]?.classes().includes('[overflow-wrap:anywhere]'),
    }).toEqual({
      names: [LONG_SHIP_NAME, 'Skiff', 'Orca'],
      hulk: [true, true, true],
      skiff: [true, true, true],
      barWidths: ['width: 100%;', 'width: 44%;'],
      longNameWraps: true,
    })
  })

  it('shows a booster ship without value, bar or ISK per hour but with the mention that it does not mine', async () => {
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ ships: shipsOfTheSeason() }), 'fr')
    await flushPromises()

    const orcaRow = wrapper.findAll('[data-testid="season-ship-row"]')[2]
    expect([
      orcaRow?.text().includes('Orca'),
      orcaRow?.text().includes('booster, ne mine pas'),
      orcaRow?.find('[data-testid="season-ship-value"]').text(),
      /\d/.test(orcaRow?.find('[data-testid="season-ship-isk-per-hour"]').text() ?? ''),
      orcaRow?.find('[data-testid="season-ship-bar"]').exists(),
    ]).toEqual([true, true, '–', false, false])
  })

  it('mentions the characters without a known ship below the table only when there are some', async () => {
    const withUnknownShips = mountPanel(seasonWithAnUnknownOperation({ ships: shipsOfTheSeason(), unknownShipCharactersCount: 2 }), 'fr')
    await flushPromises()
    const mention = withUnknownShips.wrapper.find('[data-testid="season-unknown-ships"]')
    const mentionText = mention.exists() ? mention.text() : ''
    withUnknownShips.wrapper.unmount()

    const allShipsKnown = mountPanel(seasonWithAnUnknownOperation({ ships: shipsOfTheSeason(), unknownShipCharactersCount: 0 }), 'fr')
    await flushPromises()

    expect([mentionText, allShipsKnown.wrapper.find('[data-testid="season-unknown-ships"]').exists()]).toEqual([
      '2 personnages sans vaisseau connu, hors de ce tableau',
      false,
    ])
  })

  it('shows an empty state instead of an empty ships table when no ship is known', async () => {
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ ships: [], unknownShipCharactersCount: 3 }))
    await flushPromises()

    expect([
      wrapper.find('[data-testid="season-ships-empty"]').exists(),
      wrapper.findAll('[data-testid="season-ship-row"]').length,
      wrapper.findAll('[data-testid="season-player-row"]').length,
    ]).toEqual([true, 0, 3])
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

  it('lists one row per system with its best site, its ISK per hour, its observed duration and its sites count', async () => {
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ sitesBySystem: sitesOfTheSeason() }))
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="season-site-row"]')
    expect({
      count: rows.length,
      aldranette: [rows[0]?.text().includes('Aldranette'), rows[0]?.text().includes(LONG_SITE_LABEL), rows[0]?.text().includes('1,800,000'), rows[0]?.text().includes('1 h 00 min'), rows[0]?.text().includes('3')],
      amarr: [rows[1]?.text().includes('Amarr'), rows[1]?.text().includes('Belt Amarr'), rows[1]?.text().includes('900,000'), rows[1]?.text().includes('1 h 30 min'), rows[1]?.text().includes('2')],
      longLabelWraps: rows[0]?.html().includes('[overflow-wrap:anywhere]'),
    }).toEqual({
      count: 2,
      aldranette: [true, true, true, true, true],
      amarr: [true, true, true, true, true],
      longLabelWraps: true,
    })
  })

  it('says unknown instead of 0 for a system without best site and names each unknown site with its reason', async () => {
    const withoutBestSite: MiningSeasonSystemSites = {
      solarSystemId: AMARR_ID,
      solarSystemName: 'Amarr',
      sitesCount: 2,
      bestSite: null,
      unknownSites: [{ label: 'Belt Amarr', reason: 'fewScans' }, { label: 'Belt Rens', reason: 'noPrice' }],
    }
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ sitesBySystem: [withoutBestSite] }), 'fr')
    await flushPromises()

    const rowText = textOfFirst(wrapper, 'season-site-row') ?? ''
    expect([
      rowText.includes('Inconnu'),
      /\b0\b/.test(rowText),
      rowText.includes('inconnu : Belt Amarr (un seul scan)'),
      rowText.includes('inconnu : Belt Rens (sans prix)'),
    ]).toEqual([true, false, true, true])
  })

  it('shows an empty state instead of an empty sites table when no site was scanned in the period', async () => {
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ sitesBySystem: [] }), 'fr')
    await flushPromises()

    expect([
      textOfFirst(wrapper, 'season-sites-empty'),
      wrapper.findAll('[data-testid="season-site-row"]').length,
      wrapper.findAll('[data-testid="season-player-row"]').length,
    ]).toEqual(['Aucun site scanné sur la période', 0, 3])
  })

  it('mentions the sites without a solar system with their count only when there are some', async () => {
    const withSitesWithoutSystem = mountPanel(seasonWithAnUnknownOperation({ sitesBySystem: sitesOfTheSeason(), sitesWithoutSystemCount: 2 }), 'fr')
    await flushPromises()
    const note = withSitesWithoutSystem.wrapper.find('[data-testid="season-sites-no-system"]')
    const noteText = note.exists() ? note.text() : ''
    withSitesWithoutSystem.wrapper.unmount()

    const allSitesInASystem = mountPanel(seasonWithAnUnknownOperation({ sitesBySystem: sitesOfTheSeason(), sitesWithoutSystemCount: 0 }), 'fr')
    await flushPromises()

    expect([noteText.includes('2'), allSitesInASystem.wrapper.find('[data-testid="season-sites-no-system"]').exists()]).toEqual([true, false])
  })

  it('names a system absent from the SDE with its id', async () => {
    const outsideTheSde: MiningSeasonSystemSites = {
      solarSystemId: SYSTEM_OUTSIDE_THE_SDE_ID,
      solarSystemName: null,
      sitesCount: 1,
      bestSite: { label: 'Belt Inconnue', iskPerHour: 1_200_000, observedSeconds: 3_600 },
      unknownSites: [],
    }
    const { wrapper } = mountPanel(seasonWithAnUnknownOperation({ sitesBySystem: [outsideTheSde] }), 'fr')
    await flushPromises()

    expect(textOfFirst(wrapper, 'season-site-row')?.includes('Système 30999999')).toBe(true)
  })

  it('hides the sites card of a season without operation and keeps its empty state', async () => {
    const { wrapper, t } = mountPanel(emptySeason())
    await flushPromises()

    expect([wrapper.find('[data-testid="season-sites"]').exists(), wrapper.text().includes(t('miningOperation.season.empty'))]).toEqual([false, true])
  })
})
