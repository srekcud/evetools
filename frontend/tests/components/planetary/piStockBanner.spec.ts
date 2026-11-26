import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockBanner from '@/components/planetary/PiStockBanner.vue'
import { usePlanetaryStockStore, type PlanetaryStock } from '@/stores/planetaryStock'
import {
  COOLANT_ROW,
  NO_HOME_ROW,
  PLASMA_ROW,
  UNKNOWN_EXTRACTION_ROW,
  stockOf,
} from '../../planetaryStockFixtures'

const NO_HOME = '[data-testid="stock-banner-no-home"]'
const CORP_UNKNOWN = '[data-testid="stock-banner-corp-unknown"]'
const CONSUMPTION_UNKNOWN = '[data-testid="stock-banner-consumption-unknown"]'

function mountBanner(stock: PlanetaryStock | null) {
  setActivePinia(createPinia())
  usePlanetaryStockStore().stock = stock
  return mount(PiStockBanner, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

function shown(wrapper: ReturnType<typeof mountBanner>): string[] {
  return [NO_HOME, CORP_UNKNOWN, CONSUMPTION_UNKNOWN].filter(selector => wrapper.find(selector).exists())
}

enableAutoUnmount(afterEach)

describe('PiStockBanner', () => {
  it('asks for a home location, with a way to the settings, when none is chosen and nothing else is wrong', async () => {
    const wrapper = mountBanner(stockOf([PLASMA_ROW], { homeLocationId: null, homeLocationName: null, complete: false }))

    expect(shown(wrapper)).toEqual([NO_HOME])
    expect(wrapper.get(NO_HOME).text()).toContain(en.pi.stock.banner.noHome)
    expect(wrapper.get(NO_HOME).text()).toContain(en.pi.stock.banner.goToSettings)
  })

  it('names the translated reason the corporation containers could not be read, and the generic reason for an unknown code', async () => {
    const hidden = mountBanner(stockOf([PLASMA_ROW], { corpUnknownReason: 'container_in_hidden_division', complete: false }))
    const unexpected = mountBanner(stockOf([PLASMA_ROW], { corpUnknownReason: 'a_reason_nobody_knows', complete: false }))

    expect(shown(hidden)).toEqual([CORP_UNKNOWN])
    expect(hidden.get(CORP_UNKNOWN).text()).toContain(en.industryFactory.personalStock.unknownReason.container_in_hidden_division)
    expect(hidden.get(CORP_UNKNOWN).text()).not.toContain('container_in_hidden_division')
    expect(shown(unexpected)).toEqual([CORP_UNKNOWN])
    expect(unexpected.get(CORP_UNKNOWN).text()).toContain(en.industryFactory.personalStock.unknownReason.other)
    expect(unexpected.get(CORP_UNKNOWN).text()).not.toContain('a_reason_nobody_knows')
  })

  it('flags an unknown consumption only when a row has one, shows the three notices together in order, and nothing for a complete stock', async () => {
    const complete = mountBanner(stockOf([PLASMA_ROW, COOLANT_ROW, { ...COOLANT_ROW, typeId: 91_099, weeklyConsumption: 0, coverageWeeks: null, coverageWithOrdersWeeks: null, toBuy: 0 }]))
    const unknownConsumption = mountBanner(stockOf([PLASMA_ROW, UNKNOWN_EXTRACTION_ROW], { complete: false }))
    const everything = mountBanner(stockOf([NO_HOME_ROW, UNKNOWN_EXTRACTION_ROW], {
      homeLocationId: null,
      homeLocationName: null,
      corpUnknownReason: 'container_not_found',
      complete: false,
    }))

    expect(shown(complete)).toEqual([])
    expect(shown(unknownConsumption)).toEqual([CONSUMPTION_UNKNOWN])
    expect(unknownConsumption.get(CONSUMPTION_UNKNOWN).text()).toContain(en.pi.stock.banner.consumptionUnknown)
    expect(shown(everything)).toEqual([NO_HOME, CORP_UNKNOWN, CONSUMPTION_UNKNOWN])
    const html = everything.html()
    expect(html.indexOf('stock-banner-no-home')).toBeLessThan(html.indexOf('stock-banner-corp-unknown'))
    expect(html.indexOf('stock-banner-corp-unknown')).toBeLessThan(html.indexOf('stock-banner-consumption-unknown'))
  })

  it('shows no notice before the stock is read', async () => {
    const wrapper = mountBanner(null)

    expect(shown(wrapper)).toEqual([])
  })
})
