import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AlertsPanel from '@/components/market/AlertsPanel.vue'
import type { MarketAlert } from '@/stores/market'

const TRIGGERED_ISOGEN_ALERT: MarketAlert = {
  id: 'alert-isogen',
  typeId: 37,
  typeName: 'Isogen',
  direction: 'above',
  threshold: 9_000_000,
  priceSource: 'jita_sell',
  status: 'triggered',
  currentPrice: 9_300_000,
  triggeredAt: '2026-11-09T12:00:00+00:00',
  triggeredPrice: 9_100_000,
  createdAt: '2026-11-01T09:00:00+00:00',
}

const NEVER_TRIGGERED_MEXALLON_ALERT: MarketAlert = {
  id: 'alert-mexallon',
  typeId: 36,
  typeName: 'Mexallon',
  direction: 'above',
  threshold: 61.5,
  priceSource: 'jita_sell',
  status: 'active',
  currentPrice: 55.25,
  triggeredAt: null,
  triggeredPrice: null,
  createdAt: '2026-11-02T10:00:00+00:00',
}

function mountPanel(alerts: MarketAlert[], locale: 'en' | 'fr' = 'en') {
  return mount(AlertsPanel, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
    props: { alerts },
  })
}

describe('AlertsPanel last trigger', () => {
  it('shows the date and the formatted price of the trigger of a triggered alert', () => {
    const row = mountPanel([TRIGGERED_ISOGEN_ALERT]).find('tbody tr')

    expect(row.text()).toMatch(/Triggered on (11\/09\/26|09\/11\/26) at 9\.10 M ISK/)
  })

  it('keeps showing the last trigger once the alert is active again', () => {
    const rearmedAlert: MarketAlert = { ...TRIGGERED_ISOGEN_ALERT, status: 'active', currentPrice: 8_700_000 }

    const row = mountPanel([rearmedAlert]).find('tbody tr')

    expect(row.text()).toMatch(/Triggered on (11\/09\/26|09\/11\/26) at 9\.10 M ISK/)
  })

  it('shows nothing about a trigger on an alert that never triggered', () => {
    const rows = mountPanel([TRIGGERED_ISOGEN_ALERT, NEVER_TRIGGERED_MEXALLON_ALERT]).findAll('tbody tr')

    expect(rows).toHaveLength(2)
    expect(rows[1].text()).not.toContain('Triggered on')
    expect(rows[1].text()).not.toContain('at 9.10 M')
  })

  it('formats the date and the price of the last trigger with the French locale', () => {
    const row = mountPanel([TRIGGERED_ISOGEN_ALERT], 'fr').find('tbody tr')

    expect(row.text()).toMatch(/(11\/09\/26|09\/11\/26).*9,10 M ISK/)
  })
})
