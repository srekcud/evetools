import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AlertsPanel from '@/components/market/AlertsPanel.vue'
import type { MarketAlert } from '@/stores/market'

const PYERITE_ALERT: MarketAlert = {
  id: 'alert-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  direction: 'below',
  threshold: 9.125,
  priceSource: 'jita_buy',
  status: 'triggered',
  currentPrice: 8.6875,
  triggeredAt: '2026-11-09T18:30:00+00:00',
  createdAt: '2026-11-01T09:00:00+00:00',
}

const MEXALLON_ALERT: MarketAlert = {
  id: 'alert-mexallon',
  typeId: 36,
  typeName: 'Mexallon',
  direction: 'above',
  threshold: 61.5,
  priceSource: 'jita_sell',
  status: 'active',
  currentPrice: 55.25,
  triggeredAt: null,
  createdAt: '2026-11-02T10:00:00+00:00',
}

describe('AlertsPanel edit button', () => {
  it('offers an edit button on every row and says which alert is edited', async () => {
    const wrapper = mount(AlertsPanel, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
      props: { alerts: [PYERITE_ALERT, MEXALLON_ALERT] },
    })

    const editButtons = wrapper.findAll('[data-testid="alert-edit"]')
    expect(editButtons).toHaveLength(2)

    await editButtons[1].trigger('click')

    expect(wrapper.emitted('edit')).toEqual([[MEXALLON_ALERT]])
  })

  it('offers no edit button on an expired alert and one on active and triggered alerts', () => {
    const expiredAlert: MarketAlert = { ...MEXALLON_ALERT, id: 'alert-expired', typeName: 'Isogen', status: 'expired' }
    const wrapper = mount(AlertsPanel, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
      props: { alerts: [PYERITE_ALERT, expiredAlert, MEXALLON_ALERT] },
    })

    const rows = wrapper.findAll('tbody tr')

    expect(rows).toHaveLength(3)
    expect(rows.map(row => row.find('[data-testid="alert-edit"]').exists())).toEqual([true, false, true])
  })
})
