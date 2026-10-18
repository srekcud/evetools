import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingFailedProductsWarning from '@/components/industry/shopping/ShoppingFailedProductsWarning.vue'
import type { ShoppingListFailedProduct } from '@/stores/industry/types'

const ROBOTICS_TYPE_ID = 9848
const ROBOTICS_REASON = `No blueprint or reaction found for product type ID ${ROBOTICS_TYPE_ID}`
const UNNAMED_TYPE_ID = 123456
const UNNAMED_REASON = `Types missing from the SDE: ${UNNAMED_TYPE_ID}`

function mountWarning(failedProducts: ShoppingListFailedProduct[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ShoppingFailedProductsWarning, {
    global: { plugins: [i18n] },
    props: {
      failedProducts,
      productNamesByTypeId: new Map([[ROBOTICS_TYPE_ID, 'Robotics']]),
    },
  })
}

describe('ShoppingFailedProductsWarning', () => {
  it('warns that a failed product is missing from the list, with its name and its reason', () => {
    const text = mountWarning([{ typeId: ROBOTICS_TYPE_ID, reason: ROBOTICS_REASON }]).text()

    expect(text).toContain('These products are missing from the shopping list')
    expect(text).toContain('Robotics')
    expect(text).toContain(ROBOTICS_REASON)
  })

  it('names a failed product unknown to the project by its type ID', () => {
    const text = mountWarning([{ typeId: UNNAMED_TYPE_ID, reason: UNNAMED_REASON }]).text()

    expect(text).toContain(String(UNNAMED_TYPE_ID))
    expect(text).toContain(UNNAMED_REASON)
  })

  it('renders the reason as plain text', () => {
    const wrapper = mountWarning([{ typeId: UNNAMED_TYPE_ID, reason: '<img src=x onerror=alert(1)>' }])

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('shows no warning when no product failed', () => {
    expect(mountWarning([]).find('[data-testid="failed-products-warning"]').exists()).toBe(false)
  })
})
