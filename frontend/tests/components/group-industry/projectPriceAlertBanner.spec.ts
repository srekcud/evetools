import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ProjectPriceAlertBanner from '@/components/group-industry/ProjectPriceAlertBanner.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type { ProjectPriceAlert } from '@/stores/group-industry/types'

const PRICE_ALERT: ProjectPriceAlert = {
  typeId: 11_993,
  typeName: 'Hammerhead II',
  quantity: 6,
  jitaUnitPrice: 690_000,
  breakEvenPerUnit: 760_000,
  gapPerUnit: 70_000,
}

function mountBanner(locale: 'en' | 'fr' = 'en'): VueWrapper {
  return mount(ProjectPriceAlertBanner, {
    props: { priceAlert: PRICE_ALERT },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function normalized(wrapper: VueWrapper): string {
  return wrapper.text().replace(/[  ]/g, ' ')
}

describe('ProjectPriceAlertBanner', () => {
  it('states the Jita price, the break-even price and the loss per unit sold on the market', () => {
    const banner = mountBanner()

    expect({ role: banner.get('[data-testid="price-alert-banner"]').attributes('role'), text: normalized(banner) }).toMatchObject({
      role: 'alert',
      text: expect.stringContaining('Jita (690.00 K) is below your break-even price (760.00 K). Each unit sold on the market loses 70.00 K.'),
    })
  })

  it('asks the parent to open the sales when the button is clicked, without playing any sound', async () => {
    const banner = mountBanner()

    await banner.get('[data-testid="price-alert-view-sales"]').trigger('click')

    expect({ emitted: banner.emitted('view-sales')?.length, audio: banner.findAll('audio').length }).toEqual({ emitted: 1, audio: 0 })
  })

  it('has the same wording keys in French', () => {
    expect(normalized(mountBanner('fr'))).toContain('seuil de rentabilité')
  })
})
