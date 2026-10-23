import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IskAmount from '@/components/common/IskAmount.vue'

const SELL_PRICE = 1_500_000
const NEGATIVE_PROFIT = -200_000
const MATERIAL_COST = 2_750_000

function mountAmount(props: { value: number | null; loading?: boolean; short?: boolean }, locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(IskAmount, { props, global: { plugins: [i18n] } })
}

describe('IskAmount', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows an unknown value as a dash with the unknown-value tooltip (%s)', (locale, label) => {
    const wrapper = mountAmount({ value: null }, locale)

    expect(wrapper.text()).toBe('—')
    expect(wrapper.get('[title]').attributes('title')).toBe(label)
    expect(wrapper.text()).not.toContain('0')
  })

  it('shows a known zero as zero', () => {
    const wrapper = mountAmount({ value: 0 })

    expect(wrapper.text()).toBe('0')
    expect(wrapper.find('[title="Unknown value"]').exists()).toBe(false)
  })

  it.each([
    [SELL_PRICE, 'en', '1.50 M'],
    [NEGATIVE_PROFIT, 'en', '-200.00 K'],
    [MATERIAL_COST, 'fr', '2,75 M'],
  ] as const)('shows the known amount %d formatted like formatIsk (%s)', (value, locale, formatted) => {
    const wrapper = mountAmount({ value }, locale)

    expect(wrapper.text()).toBe(formatted)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false)
    expect(wrapper.find('[title="Unknown value"]').exists()).toBe(false)
  })

  it('shows the short amount like formatIsk', () => {
    const wrapper = mountAmount({ value: SELL_PRICE, short: true })

    expect(wrapper.text()).toBe('1.50 M')
  })

  it('shows a busy skeleton without any digit while loading a known amount', () => {
    const wrapper = mountAmount({ value: SELL_PRICE, loading: true })

    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.text()).not.toMatch(/\d/)
  })

  it('shows a busy skeleton instead of the unknown dash while loading an unknown amount', () => {
    const wrapper = mountAmount({ value: null, loading: true })

    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('—')
    expect(wrapper.find('[title="Unknown value"]').exists()).toBe(false)
  })
})
