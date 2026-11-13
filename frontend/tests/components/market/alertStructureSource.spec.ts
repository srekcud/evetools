import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AlertFormModal from '@/components/market/AlertFormModal.vue'
import AlertsPanel from '@/components/market/AlertsPanel.vue'
import type { MarketAlert, StructurePrice } from '@/stores/market'

const PERIMETER_STRUCTURE_ID = 1035466617946
const HEK_STRUCTURE_ID = 1028858195912

const PERIMETER_PRICES: StructurePrice = {
  structureId: PERIMETER_STRUCTURE_ID,
  structureName: 'Perimeter - Tranquility Trading Tower',
  sell: 1_250_000,
  buy: 1_100_000,
}

const HEK_PRICES: StructurePrice = {
  structureId: HEK_STRUCTURE_ID,
  structureName: 'Hek - Spatial Storage Hub',
  sell: 2_480_000,
  buy: null,
}

const HEK_SELL_ALERT: MarketAlert = {
  id: 'alert-hek',
  typeId: 37,
  typeName: 'Isogen',
  direction: 'below',
  threshold: 2_500_000,
  priceSource: 'structure_sell',
  structureId: HEK_STRUCTURE_ID,
  structureName: 'Hek - Spatial Storage Hub',
  status: 'active',
  currentPrice: 2_480_000,
  triggeredAt: null,
  triggeredPrice: null,
  createdAt: '2026-11-02T10:00:00+00:00',
}

async function openCreationModal(structurePrices: StructurePrice[]) {
  const wrapper = mount(AlertFormModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: {
      visible: false,
      typeName: 'Isogen',
      typeId: 37,
      currentSellPrice: 52.5,
      currentBuyPrice: 46.875,
      structurePrices,
    },
  })
  await wrapper.setProps({ visible: true })
  return wrapper
}

function structureSelector(wrapper: Awaited<ReturnType<typeof openCreationModal>>) {
  return wrapper.findAll('select').find(select => select.find(`option[value="${PERIMETER_STRUCTURE_ID}"]`).exists())
}

function priceSourceSelector(wrapper: Awaited<ReturnType<typeof openCreationModal>>) {
  return wrapper.findAll('select').find(select => select.find('option[value="jita_sell"]').exists())
}

describe('AlertFormModal structure source in creation mode', () => {
  it('offers no structure selector when only one structure is known and names it in the price sources', async () => {
    const wrapper = await openCreationModal([PERIMETER_PRICES])

    expect(structureSelector(wrapper)).toBeUndefined()
    expect(priceSourceSelector(wrapper)?.find('option[value="structure_sell"]').text()).toBe('Perimeter Sell')
    expect(priceSourceSelector(wrapper)?.find('option[value="structure_buy"]').text()).toBe('Perimeter Buy')
  })

  it('offers a structure selector defaulting to the preferred structure when several structures are known', async () => {
    const wrapper = await openCreationModal([PERIMETER_PRICES, HEK_PRICES])
    await priceSourceSelector(wrapper)?.setValue('structure_sell')

    const selector = structureSelector(wrapper)
    expect(selector).toBeDefined()
    expect((selector?.element as HTMLSelectElement).value).toBe(String(PERIMETER_STRUCTURE_ID))
    expect(selector?.findAll('option').map(option => option.element.value)).toEqual([String(PERIMETER_STRUCTURE_ID), String(HEK_STRUCTURE_ID)])
  })

  it('shows the sell and buy prices of the selected structure and renders an unknown price as a dash', async () => {
    const wrapper = await openCreationModal([PERIMETER_PRICES, HEK_PRICES])
    await priceSourceSelector(wrapper)?.setValue('structure_sell')

    expect(wrapper.text()).toContain('Perimeter Sell:1.25 M')
    expect(wrapper.text()).toContain('Perimeter Buy:1.10 M')

    await structureSelector(wrapper)?.setValue(String(HEK_STRUCTURE_ID))

    expect(wrapper.text()).toContain('Hek Sell:2.48 M')
    expect(wrapper.text()).toContain('Hek Buy:---')
    expect(wrapper.text()).not.toContain('Perimeter Sell')
    expect(priceSourceSelector(wrapper)?.find('option[value="structure_sell"]').text()).toBe('Hek Sell')
  })

  it('submits the structureId of the selected structure for a structure source', async () => {
    const wrapper = await openCreationModal([PERIMETER_PRICES, HEK_PRICES])
    await priceSourceSelector(wrapper)?.setValue('structure_buy')
    await structureSelector(wrapper)?.setValue(String(HEK_STRUCTURE_ID))
    await wrapper.find('input[type="number"]').setValue(2_000_000)
    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('submit')).toEqual([[
      { typeId: 37, direction: 'below', threshold: 2_000_000, priceSource: 'structure_buy', structureId: HEK_STRUCTURE_ID },
    ]])
  })

  it('submits no structureId for a Jita source even when several structures are known', async () => {
    const wrapper = await openCreationModal([PERIMETER_PRICES, HEK_PRICES])
    await priceSourceSelector(wrapper)?.setValue('jita_buy')
    await wrapper.find('input[type="number"]').setValue(46)
    await wrapper.find('form').trigger('submit')

    const payload = (wrapper.emitted('submit') ?? [])[0][0] as Record<string, unknown>
    expect(payload).toEqual({ typeId: 37, direction: 'below', threshold: 46, priceSource: 'jita_buy' })
    expect(Object.keys(payload)).not.toContain('structureId')
  })
})

describe('Structure alert labels', () => {
  it('labels an alert in the list with its own structure and not with the preferred one', () => {
    const wrapper = mount(AlertsPanel, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
      props: { alerts: [HEK_SELL_ALERT], structureName: 'Perimeter - Tranquility Trading Tower' },
    })

    const row = wrapper.find('tbody tr')
    expect(row.text()).toContain('Hek Sell')
    expect(row.text()).not.toContain('Perimeter')
  })

  it('labels an alert whose structure is no longer known with the generic structure name', () => {
    const unknownStructureAlert: MarketAlert = { ...HEK_SELL_ALERT, priceSource: 'structure_buy', structureName: null }
    const wrapper = mount(AlertsPanel, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
      props: { alerts: [unknownStructureAlert], structureName: 'Perimeter - Tranquility Trading Tower' },
    })

    const row = wrapper.find('tbody tr')
    expect(row.text()).toContain('Structure Buy')
    expect(row.text()).not.toContain('Perimeter')
  })

  it('labels the price source of the edit modal with the structure of the alert', async () => {
    const wrapper = mount(AlertFormModal, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
      props: {
        visible: false,
        typeName: 'Isogen',
        typeId: 37,
        currentSellPrice: 52.5,
        currentBuyPrice: 46.875,
        structureName: 'Perimeter - Tranquility Trading Tower',
        editingAlert: HEK_SELL_ALERT,
      },
    })
    await wrapper.setProps({ visible: true })

    expect(wrapper.text()).toContain('Hek Sell')
    expect(wrapper.text()).not.toContain('Perimeter')
  })
})
