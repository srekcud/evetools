import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiKpiCards from '@/components/planetary/PiKpiCards.vue'

function mountKpiCards(thresholdMinutes: number) {
  return mount(PiKpiCards, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: {
      totalColonies: 4,
      totalCharacters: 2,
      activeExtractors: 5,
      expiredExtractors: 1,
      totalExtractors: 6,
      expiringExtractors: 2,
      estimatedDailyIsk: 12_500_000,
      thresholdMinutes,
    },
  })
}

describe('PiKpiCards expiring soon window', () => {
  it('names the threshold of the user in hours', () => {
    const text = mountKpiCards(360).text()

    expect(text).toMatch(/\b6\s?h/)
    expect(text).not.toMatch(/\b24\s?h/)
  })

  it('names one day for the default threshold', () => {
    expect(mountKpiCards(1440).text()).toMatch(/\b24\s?h/)
  })
})
