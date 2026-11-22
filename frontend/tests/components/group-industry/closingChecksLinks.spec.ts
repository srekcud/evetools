import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ClosingChecksList from '@/components/group-industry/ClosingChecksList.vue'
import type { ClosingChecksData } from '@/stores/group-industry/types'
import { closingChecks } from './projectSettlementFixtures'

enableAutoUnmount(afterEach)

function mountWith(checks: ClosingChecksData): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ClosingChecksList, { props: { checks }, global: { plugins: [i18n] } })
}

const LINK = (key: string) => `[data-testid="closing-check-link-${key}"]`

const EVERYTHING_FAILING = closingChecks({
  pendingContributionCount: 2,
  unsoldProducts: [{ typeId: 2185, typeName: 'Hammerhead II', quantity: 20 }],
  marginPercent: null,
  transfersTotal: 3,
  transfersReceived: 1,
})

describe('closing checks linking to their tab', () => {
  it('offers no link on a check that passes', () => {
    const wrapper = mountWith(closingChecks())

    expect(wrapper.findAll('[data-testid^="closing-check-link-"]')).toHaveLength(0)
  })

  it('links every failing check', () => {
    const wrapper = mountWith(EVERYTHING_FAILING)

    expect(wrapper.findAll('[data-testid^="closing-check-link-"]').map(link => link.attributes('data-testid'))).toEqual([
      'closing-check-link-pendingContributions',
      'closing-check-link-unsoldProducts',
      'closing-check-link-finalMargin',
      'closing-check-link-transfers',
    ])
  })

  it('opens the contributions tab from the pending contributions check', async () => {
    const wrapper = mountWith(EVERYTHING_FAILING)

    await wrapper.get(LINK('pendingContributions')).trigger('click')

    expect(wrapper.emitted('open-tab')).toEqual([['contributions']])
  })

  it('opens the sales tab from the unsold products check', async () => {
    const wrapper = mountWith(EVERYTHING_FAILING)

    await wrapper.get(LINK('unsoldProducts')).trigger('click')

    expect(wrapper.emitted('open-tab')).toEqual([['sales']])
  })

  it('opens the finances tab from the margin and the transfers checks', async () => {
    const wrapper = mountWith(EVERYTHING_FAILING)

    await wrapper.get(LINK('finalMargin')).trigger('click')
    await wrapper.get(LINK('transfers')).trigger('click')

    expect(wrapper.emitted('open-tab')).toEqual([['distribution'], ['distribution']])
  })
})
