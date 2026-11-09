import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ClosingChecksList from '@/components/group-industry/ClosingChecksList.vue'
import type { ClosingChecksData } from '@/stores/group-industry/types'
import { closingChecks } from './projectSettlementFixtures'

enableAutoUnmount(afterEach)

function mountWith(checks: ClosingChecksData) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ClosingChecksList, { props: { checks }, global: { plugins: [i18n] } })
}

const CHECK = (key: string) => `[data-testid="closing-check-${key}"]`

describe('ClosingChecksList', () => {
  it('marks every check as satisfied when nothing is left to do', () => {
    const wrapper = mountWith(closingChecks())

    expect(wrapper.findAll('[data-testid^="closing-check-"]').map(check => check.attributes('data-ok'))).toEqual(['true', 'true', 'true', 'true'])
  })

  it('counts the contributions still waiting for a review', () => {
    const wrapper = mountWith(closingChecks({ pendingContributionCount: 3 }))

    expect(wrapper.get(CHECK('pendingContributions')).attributes('data-ok')).toBe('false')
    expect(wrapper.get(CHECK('pendingContributions')).text()).toContain('3')
  })

  it('names every unsold item with its remaining quantity', () => {
    const wrapper = mountWith(closingChecks({
      unsoldProducts: [
        { typeId: 2185, typeName: 'Hammerhead II', quantity: 20 },
        { typeId: 2206, typeName: 'Hornet II', quantity: 5 },
      ],
    }))

    const text = wrapper.get(CHECK('unsoldProducts')).text()
    expect(wrapper.get(CHECK('unsoldProducts')).attributes('data-ok')).toBe('false')
    expect(text).toContain('Hammerhead II')
    expect(text).toContain('20')
    expect(text).toContain('Hornet II')
  })

  it('shows the final margin, and says it is unknown rather than 0 when it is', () => {
    expect(mountWith(closingChecks({ marginPercent: 55 })).get(CHECK('finalMargin')).text()).toContain('55')

    const unknown = mountWith(closingChecks({ marginPercent: null }))
    expect(unknown.get(CHECK('finalMargin')).attributes('data-ok')).toBe('false')
    expect(unknown.get(CHECK('finalMargin')).text()).not.toContain('0%')
  })

  it('shows how many transfers are ticked out of the total', () => {
    const wrapper = mountWith(closingChecks({ transfersTotal: 3, transfersReceived: 1 }))

    expect(wrapper.get(CHECK('transfers')).attributes('data-ok')).toBe('false')
    expect(wrapper.get(CHECK('transfers')).text()).toContain('1 / 3')
  })
})
