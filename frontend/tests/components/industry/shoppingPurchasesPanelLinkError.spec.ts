import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingPurchasesPanel from '@/components/industry/shopping/ShoppingPurchasesPanel.vue'
import type { PurchaseSuggestion } from '@/stores/industry/types'

const ALREADY_LINKED_EN = 'This transaction is already linked to a purchase. Suggestions have been refreshed.'

const LINKED_SUGGESTION: PurchaseSuggestion = {
  transactionId: 6_400_000_003,
  transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-205000000103',
  typeId: 9104,
  typeName: 'Compact Thruster',
  quantity: 25,
  unitPrice: 230,
  totalPrice: 5750,
  date: '2026-10-22T18:45:00+00:00',
  characterName: 'Pilot One',
  locationId: 60003760,
  alreadyLinked: true,
}

function mountPanel(suggestions: PurchaseSuggestion[], linkError: string | null) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ShoppingPurchasesPanel, {
    global: { plugins: [i18n] },
    props: {
      suggestions,
      projectPurchases: [],
      projectPurchasesTotalCost: 0,
      materialCost: null,
      purchasesLoading: false,
      canFindStepForType: () => true,
      linkError,
    },
  })
}

describe('ShoppingPurchasesPanel link error', () => {
  it.each([
    ['with suggestions', [LINKED_SUGGESTION]],
    ['without suggestions', []],
  ])('shows the link error as an alert in the suggestions card %s', (_label, suggestions) => {
    const wrapper = mountPanel(suggestions, ALREADY_LINKED_EN)

    const alert = wrapper.find('[data-testid="purchase-link-error"]')
    expect(alert.exists()).toBe(true)
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toBe(ALREADY_LINKED_EN)
  })

  it('shows no link error when there is none', () => {
    const wrapper = mountPanel([LINKED_SUGGESTION], null)

    expect(wrapper.find('[data-testid="purchase-link-error"]').exists()).toBe(false)
  })
})
