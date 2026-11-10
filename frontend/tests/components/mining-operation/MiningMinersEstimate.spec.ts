import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import MiningMinersEstimate from '@/components/mining-operation/MiningMinersEstimate.vue'
import type { MiningMinersEstimate as MinersEstimate, MiningMinersEstimateType } from '@/stores/miningOperation'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const VELDSPAR_TYPE_ID = 1230
const SCORDITE_TYPE_ID = 1228
const FIRST_CREW_SIZE = 4
const LAST_CREW_SIZE = 9

function estimatedType(overrides: Partial<MiningMinersEstimateType> = {}): MiningMinersEstimateType {
  return {
    typeId: VELDSPAR_TYPE_ID,
    typeName: 'Veldspar',
    status: 'estimated',
    knownSiteCount: 3,
    workMinerMinutes: 605.67,
    minersNeeded: 8,
    deadline: '2026-11-11T11:00:00+00:00',
    availableMinutes: 80,
    durations: [4, 5, 6, 7, 8, 9].map(miners => ({ miners, minutes: 605.67 / miners, beforeDeadline: 605.67 / miners <= 80 })),
    sites: [
      { label: 'A-3', firstScanAt: '2026-10-22T20:00:00+00:00', lifespanMinutes: 104, miners: 6, workMinerMinutes: 624, unknownReason: null },
      { label: 'A-5', firstScanAt: '2026-10-20T20:00:00+00:00', lifespanMinutes: 121, miners: 5, workMinerMinutes: 605, unknownReason: null },
      { label: 'A-1', firstScanAt: '2026-10-01T20:00:00+00:00', lifespanMinutes: 98, miners: 6, workMinerMinutes: 588, unknownReason: null },
    ],
    ...overrides,
  }
}

function insufficientType(): MiningMinersEstimateType {
  return estimatedType({
    typeId: SCORDITE_TYPE_ID,
    typeName: 'Scordite',
    status: 'insufficient_history',
    knownSiteCount: 1,
    workMinerMinutes: null,
    minersNeeded: null,
    deadline: null,
    availableMinutes: null,
    durations: [],
    sites: [{ label: 'S-1', firstScanAt: '2026-10-25T20:00:00+00:00', lifespanMinutes: 80, miners: 3, workMinerMinutes: 240, unknownReason: null }],
  })
}

function mountEstimate(types: MiningMinersEstimateType[]) {
  const estimate: MinersEstimate = { operationId: '0198c1f2-6a5e-7d11-9c44-5b2f3a0e9d71', startAt: '2026-11-11T09:40:00+00:00', types }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(MiningMinersEstimate, { props: { estimate }, global: { plugins: [i18n] } })
}

function byTestId(wrapper: ReturnType<typeof mountEstimate>, testId: string) {
  return wrapper.find(`[data-testid="${testId}"]`)
}

enableAutoUnmount(afterEach)

describe('MiningMinersEstimate', () => {
  it('shows the miners needed before the downtime for the first type and one duration cell per crew size flagged before or after the downtime', () => {
    const wrapper = mountEstimate([estimatedType()])

    expect(byTestId(wrapper, 'miners-needed').exists()).toBe(true)
    expect(byTestId(wrapper, 'miners-needed').attributes('data-miners')).toBe('8')
    const cells = wrapper.findAll('[data-testid="miners-duration"]')
    expect(cells.map(cell => cell.attributes('data-miners'))).toEqual(['4', '5', '6', '7', '8', '9'])
    expect(cells.map(cell => cell.attributes('data-before-deadline'))).toEqual(['false', 'false', 'false', 'false', 'true', 'true'])
    expect(cells[0].attributes('data-miners')).toBe(String(FIRST_CREW_SIZE))
    expect(cells[cells.length - 1].attributes('data-miners')).toBe(String(LAST_CREW_SIZE))
  })

  it('shows not enough history with the number of sites found and proposes no duration when the type has fewer than three sites', () => {
    const wrapper = mountEstimate([insufficientType()])

    expect(byTestId(wrapper, 'miners-insufficient').exists()).toBe(true)
    expect(byTestId(wrapper, 'miners-insufficient').text()).toContain('1')
    expect(byTestId(wrapper, 'miners-needed').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="miners-duration"]')).toHaveLength(0)
  })

  it('lists each site of the sample with its crew, never showing a workload of zero for a site without any miner', () => {
    const noMinerSite = { label: 'N-1', firstScanAt: '2026-09-21T20:00:00+00:00', lifespanMinutes: 100, miners: 0, workMinerMinutes: null, unknownReason: 'no_miner' as const }
    const type = estimatedType()
    const wrapper = mountEstimate([{ ...type, sites: [...type.sites, noMinerSite] }])

    const rows = wrapper.findAll('[data-testid="miners-site-row"]')
    expect(rows).toHaveLength(4)
    expect(rows.map(row => row.attributes('data-unknown-reason') ?? null)).toEqual([null, null, null, 'no_miner'])
    expect(rows[3].find('[data-testid="miners-site-work"]').text()).not.toMatch(/^0\b/)
    expect(rows[3].find('[data-testid="miners-site-work"]').attributes('data-unknown')).toBe('true')
  })

  it('offers one tab per type and switches the displayed estimate to the type clicked', async () => {
    const wrapper = mountEstimate([estimatedType(), insufficientType()])

    expect(wrapper.findAll('[data-testid="miners-type-tab"]').map(tab => tab.attributes('data-type-id'))).toEqual([String(VELDSPAR_TYPE_ID), String(SCORDITE_TYPE_ID)])
    expect(byTestId(wrapper, 'miners-estimate').attributes('data-active-type-id') ?? null).toBe(String(VELDSPAR_TYPE_ID))

    await wrapper.findAll('[data-testid="miners-type-tab"]')[1].trigger('click')

    expect(byTestId(wrapper, 'miners-estimate').attributes('data-active-type-id')).toBe(String(SCORDITE_TYPE_ID))
    expect(byTestId(wrapper, 'miners-insufficient').exists()).toBe(true)
  })

  it('tells that no site was scanned until depletion instead of showing tabs when the sample is empty', () => {
    const wrapper = mountEstimate([])

    expect(byTestId(wrapper, 'miners-empty').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="miners-type-tab"]')).toHaveLength(0)
  })
})
