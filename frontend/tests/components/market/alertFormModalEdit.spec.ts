import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AlertFormModal from '@/components/market/AlertFormModal.vue'
import type { MarketAlert } from '@/stores/market'

const TRIGGERED_PYERITE_ALERT: MarketAlert = {
  id: 'alert-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  direction: 'below',
  threshold: 9.125,
  priceSource: 'jita_buy',
  status: 'triggered',
  currentPrice: 8.6875,
  triggeredAt: '2026-11-09T18:30:00+00:00',
  triggeredPrice: 8.6875,
  createdAt: '2026-11-01T09:00:00+00:00',
}

const ACTIVE_MEXALLON_ALERT: MarketAlert = {
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

async function openModal(editingAlert: MarketAlert | null) {
  const wrapper = mount(AlertFormModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: {
      visible: false,
      typeName: editingAlert?.typeName ?? 'Isogen',
      typeId: editingAlert?.typeId ?? 37,
      currentSellPrice: 52.5,
      currentBuyPrice: 46.875,
      editingAlert,
    },
  })
  await wrapper.setProps({ visible: true })
  return wrapper
}

describe('AlertFormModal in edit mode', () => {
  it('shows the item and the price source of the alert without letting them change', async () => {
    const wrapper = await openModal(TRIGGERED_PYERITE_ALERT)

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.text()).toContain('Pyerite')
    expect(wrapper.text()).toContain('Jita Buy')
  })

  it('is prefilled with the direction and the threshold of the alert', async () => {
    const wrapper = await openModal(ACTIVE_MEXALLON_ALERT)

    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('update')).toEqual([[{ id: 'alert-mexallon', direction: 'above', threshold: 61.5 }]])
  })

  it('saves the edited direction and threshold as an update and not as a creation', async () => {
    const wrapper = await openModal(TRIGGERED_PYERITE_ALERT)

    await wrapper.find('input[type="number"]').setValue(8)
    const aboveButton = wrapper.findAll('button[type="button"]').find(button => button.text() === 'Above')
    await aboveButton?.trigger('click')
    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('update')).toEqual([[{ id: 'alert-pyerite', direction: 'above', threshold: 8 }]])
    expect(wrapper.emitted('submit')).toBeUndefined()
  })

  it('says that saving a triggered alert re-arms it and says nothing of the kind for an active alert', async () => {
    const triggered = await openModal(TRIGGERED_PYERITE_ALERT)
    const active = await openModal(ACTIVE_MEXALLON_ALERT)

    expect(triggered.find('button[type="submit"]').text()).toMatch(/re-?arm/i)
    expect(active.find('button[type="submit"]').text()).not.toMatch(/re-?arm/i)
  })

  it('refuses a threshold of zero', async () => {
    const wrapper = await openModal(TRIGGERED_PYERITE_ALERT)

    await wrapper.find('input[type="number"]').setValue(0)

    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })
})

describe('AlertFormModal in creation mode', () => {
  it('still lets the price source be chosen and submits a creation', async () => {
    const wrapper = await openModal(null)

    await wrapper.find('select').setValue('jita_buy')
    await wrapper.find('input[type="number"]').setValue(46)
    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('submit')).toEqual([[{ typeId: 37, direction: 'below', threshold: 46, priceSource: 'jita_buy' }]])
    expect(wrapper.emitted('update')).toBeUndefined()
  })
})
