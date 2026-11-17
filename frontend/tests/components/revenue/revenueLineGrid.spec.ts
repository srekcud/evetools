import { enableAutoUnmount, RouterLinkStub } from '@vue/test-utils'
import { NET_SERIES, WEEKLY_PERIOD, column, mountGrid } from './revenueLineGridHarness'

enableAutoUnmount(afterEach)

const BOUNTIES = 500_000_000
const SPENDING = -100_000_000
const UNCATEGORISED = 142_000_000

describe('RevenueLineGrid', () => {
  it('shows one aggregated row with a cell per column and the net on the right', () => {
    const wrapper = mountGrid({
      columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES }), column({ id: 'spend', label: 'Spending', value: SPENDING, tone: 'loss' })],
    })

    expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(3)
    expect(wrapper.get('[data-cell="bounty"]').text()).toBe('500.00 M')
    expect(wrapper.get('[data-cell="spend"]').text()).toBe('-100.00 M')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('400.00 M')
  })

  it('shows costs in red', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'spend', label: 'Spending', value: SPENDING, tone: 'loss' })] })

    const cell = wrapper.get('[data-cell="spend"] div')
    expect(cell.attributes('data-state')).toBe('cost')
    expect(cell.classes()).toContain('text-red-300')
  })

  it('hatches an unknown column and leaves it out of the net', () => {
    const wrapper = mountGrid({
      columns: [column({ id: 'sold', label: 'Sold', value: BOUNTIES, tone: 'mining' }), column({ id: 'uncategorised', label: 'Uncategorised', value: UNCATEGORISED, kind: 'unknown' })],
    })

    const cell = wrapper.get('[data-cell="uncategorised"] div')
    expect(cell.attributes('data-state')).toBe('unknown')
    expect(cell.attributes('style')).toContain('repeating-linear-gradient')
    expect(cell.attributes('title')).toContain('excluded from the net')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('500.00 M')
    expect(wrapper.get('[data-testid="revenue-legend"]').text()).toContain('unknown, excluded from the net')
  })

  it('shows a column without a value as unknown, never as zero', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'loot', label: 'Loot', value: null })] })

    const cell = wrapper.get('[data-cell="loot"] div')
    expect(cell.text()).toBe('—')
    expect(cell.attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('—')
  })

  it('keeps a known zero as zero', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'loot', label: 'Loot', value: 0 })] })

    expect(wrapper.get('[data-cell="loot"]').text()).toBe('0')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('0')
  })

  it('marks an estimate with a tilde, a dashed outline and a tilde on the net', () => {
    const wrapper = mountGrid({
      columns: [column({ id: 'pve', label: 'PVE', value: BOUNTIES }), column({ id: 'pi', label: 'Planetary', value: 64_000_000, kind: 'estimate', tone: 'planetary' })],
    })

    const cell = wrapper.get('[data-cell="pi"] div')
    expect(cell.text()).toBe('~64.00 M')
    expect(cell.attributes('style')).toContain('dashed')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('~564.00 M')
    expect(wrapper.get('[data-testid="revenue-legend"]').text()).toContain('~ estimate')
  })

  it('shows the change versus the previous period of the same length, unknown as a dash', () => {
    const wrapper = mountGrid({
      columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES, change: 18.4 }), column({ id: 'loot', label: 'Loot', value: 1, change: null })],
    })

    expect(wrapper.text()).toContain('vs previous 7 days')
    expect(wrapper.get('[data-change="bounty"]').text()).toBe('▲ 18 %')
    expect(wrapper.get('[data-change="loot"]').text()).toBe('—')
    expect(wrapper.get('[data-testid="net-change"]').text()).toBe('▲ 5 %')
  })

  it('shows a fall as a down arrow and a rising cost as unfavourable', () => {
    const wrapper = mountGrid({
      columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES, change: -61 }), column({ id: 'spend', label: 'Spending', value: SPENDING, tone: 'loss', change: 25 })],
    })

    expect(wrapper.get('[data-change="bounty"]').text()).toBe('▼ 61 %')
    expect(wrapper.get('[data-change="spend"] span').classes()).toContain('text-red-400')
  })

  it('draws a regularity strip per column and for the net, per day then per week', () => {
    const daily = (wrapper: ReturnType<typeof mountGrid>) => wrapper.findAll('[data-testid="regularity-strip"]')
    const perDay = mountGrid({ columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES })] })
    expect(daily(perDay)).toHaveLength(2)
    expect(daily(perDay)[0].text()).toBe('1/7 days')
    expect(perDay.text()).toContain('per day')

    const perWeek = mountGrid({ columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES })], period: WEEKLY_PERIOD })
    expect(daily(perWeek)[0].text()).toBe('1/13 weeks')
    expect(perWeek.text()).toContain('per week')
  })

  it('shows an unknown daily series as a hatched strip, not as 0 days', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES, daily: null })] })

    expect(wrapper.findAll('[data-testid="regularity-strip"]')[0].text()).toBe('—')
  })

  it('names the two largest contributors, groups the rest and lists everyone in the tooltip', () => {
    const wrapper = mountGrid({
      columns: [
        column({
          id: 'bounty',
          label: 'Bounties',
          value: BOUNTIES,
          contributors: [
            { id: 1, name: 'Main', value: 600 },
            { id: 2, name: 'Second', value: 250 },
            { id: 3, name: 'Alt A', value: 100 },
            { id: 4, name: 'Alt B', value: 50 },
          ],
        }),
      ],
      net: NET_SERIES,
    })

    const contributors = wrapper.findAll('[data-testid="contributors"]')[0]
    expect(contributors.text()).toContain('Main60 %')
    expect(contributors.text()).toContain('Second25 %')
    expect(contributors.text()).toContain('+2 characters15 %')
    expect(contributors.attributes('title')).toContain('Alt B')
    expect(contributors.attributes('title')).toContain('Alt A')
  })

  it('uses the singular for one grouped contributor and the product wording for products', () => {
    const wrapper = mountGrid({
      contributorUnit: 'products',
      columns: [
        column({
          id: 'sales',
          label: 'Sales',
          value: BOUNTIES,
          contributors: [
            { id: 1, name: 'A', value: 5 },
            { id: 2, name: 'B', value: 4 },
            { id: 3, name: 'C', value: 1 },
          ],
        }),
      ],
    })

    expect(wrapper.text()).toContain('+1 product')
    expect(wrapper.text()).not.toContain('+1 products')
    expect(wrapper.text()).toContain('Products')
  })

  it('truncates a long contributor name and keeps it in a tooltip', () => {
    const longName = 'Commandant Aurelius Vandermeer-Okonkwo'
    const wrapper = mountGrid({ columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES, contributors: [{ id: 1, name: longName, value: 5 }] })] })

    const name = wrapper.get('[data-testid="contributors"] span.truncate')
    expect(name.attributes('title')).toBe(longName)
  })

  it('shows unknown contributors as a dash', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'bounty', label: 'Bounties', value: BOUNTIES, contributors: null })] })

    expect(wrapper.get('[title="Contributors unknown"]').text()).toBe('—')
    expect(wrapper.findAll('[data-testid="contributors"]')).toHaveLength(1)
  })

  it('shows numbered badges on a header and on a cell, with the legend', () => {
    const wrapper = mountGrid({
      columns: [column({ id: 'loot', label: 'Loot', value: BOUNTIES, headerBadge: 1 }), column({ id: 'sold', label: 'Sold', value: 5, cellBadge: 2 })],
    })

    expect(wrapper.get('[data-testid="header-badge"]').text()).toBe('1')
    expect(wrapper.get('[data-testid="cell-badge"]').text()).toBe('2')
    expect(wrapper.get('[data-testid="header-badge"]').attributes('aria-label')).toBe('To fix, item 1')
    expect(wrapper.get('[data-testid="revenue-legend"]').text()).toContain('to fix')
  })

  it('has no badge legend without a badge', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'loot', label: 'Loot', value: BOUNTIES })] })

    expect(wrapper.find('[data-testid="header-badge"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="revenue-legend"]').text()).not.toContain('to fix')
  })

  it('links a column header to its page when it has a target', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'pve', label: 'PVE', value: BOUNTIES, to: '/pve/revenue' })] })

    expect(wrapper.get('[data-column="pve"]').getComponent(RouterLinkStub).props('to')).toBe('/pve/revenue')
  })

  it('uses the custom net label', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'sales', label: 'Sales', value: BOUNTIES })], netLabel: 'Profit' })

    expect(wrapper.find('thead').text()).toContain('Profit')
  })

  it('is worded in French', () => {
    const wrapper = mountGrid({ columns: [column({ id: 'pi', label: 'Planétaire', value: 1, kind: 'estimate', tone: 'planetary', headerBadge: 1 })] }, 'fr')

    expect(wrapper.text()).toContain('vs 7 j préc.')
    expect(wrapper.text()).toContain('Régularité')
    expect(wrapper.text()).toContain('Contributeurs')
    expect(wrapper.get('[data-testid="revenue-legend"]').text()).toContain('~ estimation')
  })
})
