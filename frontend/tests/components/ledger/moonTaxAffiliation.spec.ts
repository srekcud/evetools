import { enableAutoUnmount } from '@vue/test-utils'
import { apiRequest, authFetch } from '@/services/api'
import {
  DUE_STATEMENT,
  NOW,
  defaultRoutes,
  mountMoonTax,
  statement,
  statementCell,
  statementsPath,
  CORPORATION_A,
} from './moonTaxHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

enableAutoUnmount(afterEach)

const NO_ALLIANCE_ID = 98000044
const UNREAD_CORPORATION_ID = 98000045

const WITHOUT_ALLIANCE = statement({
  id: '0195f3a0-3000-7000-8000-000000000021',
  recordedCorporationId: NO_ALLIANCE_ID,
  recordedCorporationName: null,
  allianceId: null,
  allianceName: null,
  affiliationKnown: true,
})

const WITHOUT_AFFILIATION = statement({
  id: '0195f3a0-3000-7000-8000-000000000022',
  recordedCorporationId: null,
  recordedCorporationName: null,
  allianceId: null,
  allianceName: null,
  affiliationKnown: false,
})

const UNREAD_AFFILIATION = statement({
  id: '0195f3a0-3000-7000-8000-000000000023',
  recordedCorporationId: UNREAD_CORPORATION_ID,
  recordedCorporationName: null,
  allianceId: null,
  allianceName: null,
  affiliationKnown: false,
})

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  vi.mocked(authFetch).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountWithAffiliations() {
  return mountMoonTax({
    ...defaultRoutes(),
    [`GET ${statementsPath(CORPORATION_A)}`]: [DUE_STATEMENT, WITHOUT_ALLIANCE, WITHOUT_AFFILIATION, UNREAD_AFFILIATION],
  })
}

describe('MoonTaxStatementsTable affiliation columns', () => {
  it('shows the recorded corporation by name, then by id, then as unknown, never an empty cell', async () => {
    const wrapper = await mountWithAffiliations()

    expect([0, 1, 2, 3].map(index => statementCell(wrapper, index, 'corporation').text())).toEqual([
      'Rock Hollow Mining',
      `#${NO_ALLIANCE_ID}`,
      'unknown',
      `#${UNREAD_CORPORATION_ID}`,
    ])
  })

  it('shows the alliance by name, no alliance for a known corporation outside any alliance and unknown when the affiliation is unread', async () => {
    const wrapper = await mountWithAffiliations()

    expect([0, 1, 2, 3].map(index => statementCell(wrapper, index, 'alliance').text())).toEqual([
      'Pandemic Legion',
      'No alliance',
      'unknown',
      'unknown',
    ])
  })
})
