import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { RANKING_PRODUCT_B } from '../../planetaryPlannerFixtures'
import { TEMPLATE_B_AND_M, TEMPLATE_B_LINE, TEMPLATE_PRODUCT_M } from '../../planetaryTemplatesFixtures'
import { deletedEndpoints, mountTemplates, rowOfTemplate, templateRows, type TemplatesWrapper } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const TEMPLATE_PRODUCTIONS = [{ typeId: RANKING_PRODUCT_B, targetPerDay: 60 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }]
const PLAN_PRODUCTIONS = [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }]

async function click(wrapper: TemplatesWrapper, templateId: string, testId: string) {
  await rowOfTemplate(wrapper, templateId).get(`[data-testid="${testId}"]`).trigger('click')
  await flushPromises()
}

async function answer(wrapper: TemplatesWrapper, testId: 'template-confirm-accept' | 'template-confirm-cancel') {
  await wrapper.get(`[data-testid="${testId}"]`).trigger('click')
  await flushPromises()
}

describe('PiPlannerTemplates load', () => {
  it('replaces the plan at once, without confirmation, when the current plan is empty', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M] })

    await click(wrapper, 'tpl-b-and-m', 'template-load')

    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
    expect(store.productions).toEqual(TEMPLATE_PRODUCTIONS)
    expect(store.settings).toEqual({ purchaseSource: 'jita-buy', saleSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 })
    expect(store.selectedIndex).toBe(0)
  })

  it('asks for a confirmation in place when the plan is not empty and changes nothing before the answer', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M] })
    store.addProduction(RANKING_PRODUCT_B, 120)

    await click(wrapper, 'tpl-b-and-m', 'template-load')

    const confirmation = wrapper.get('[data-testid="template-confirm"]')
    expect(confirmation.attributes('data-action')).toBe('load')
    expect(confirmation.attributes('data-template-id')).toBe('tpl-b-and-m')
    expect(confirmation.text()).not.toBe('')
    expect(wrapper.findAll('[data-testid="template-confirm"]')).toHaveLength(1)
    expect(store.productions).toEqual(PLAN_PRODUCTIONS)
  })

  it('replaces the plan once the player accepts the confirmation and removes the confirmation line', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await click(wrapper, 'tpl-b-and-m', 'template-load')

    await answer(wrapper, 'template-confirm-accept')

    expect(store.productions).toEqual(TEMPLATE_PRODUCTIONS)
    expect(store.choices).toEqual({ [RANKING_PRODUCT_B]: 'produce', [TEMPLATE_PRODUCT_M]: 'buy' })
    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
  })

  it('keeps the plan when the player cancels the confirmation', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await click(wrapper, 'tpl-b-and-m', 'template-load')

    await answer(wrapper, 'template-confirm-cancel')

    expect(store.productions).toEqual(PLAN_PRODUCTIONS)
    expect(store.settings).toEqual({ purchaseSource: 'jita-sell', saleSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 })
    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
  })
})

describe('PiPlannerTemplates add', () => {
  it('adds the productions after those of the plan and completes the choices without asking for a confirmation', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    store.setChoice(RANKING_PRODUCT_B, 'buy')

    await click(wrapper, 'tpl-b-and-m', 'template-add')

    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
    expect(store.productions).toEqual([...PLAN_PRODUCTIONS, ...TEMPLATE_PRODUCTIONS])
    expect(store.choices).toEqual({ [RANKING_PRODUCT_B]: 'buy', [TEMPLATE_PRODUCT_M]: 'buy' })
    expect(store.settings).toEqual({ purchaseSource: 'jita-sell', saleSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 })
  })

  it('loads the template when the plan is empty', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M] })

    await click(wrapper, 'tpl-b-and-m', 'template-add')

    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
    expect(store.productions).toEqual(TEMPLATE_PRODUCTIONS)
    expect(store.selectedIndex).toBe(0)
  })
})

describe('PiPlannerTemplates delete', () => {
  it('asks for a confirmation in place and deletes nothing before the answer, nor after a cancel', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, TEMPLATE_B_LINE] })

    await click(wrapper, 'tpl-b-line', 'template-delete')

    const confirmation = wrapper.get('[data-testid="template-confirm"]')
    expect(confirmation.attributes('data-action')).toBe('delete')
    expect(confirmation.attributes('data-template-id')).toBe('tpl-b-line')
    expect(deletedEndpoints()).toEqual([])
    expect(templateRows(wrapper)).toHaveLength(2)

    await answer(wrapper, 'template-confirm-cancel')

    expect(deletedEndpoints()).toEqual([])
    expect(templateRows(wrapper)).toHaveLength(2)
    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
  })

  it('deletes the template through the API once the player accepts and drops its row', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, TEMPLATE_B_LINE] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await click(wrapper, 'tpl-b-line', 'template-delete')

    await answer(wrapper, 'template-confirm-accept')

    expect(deletedEndpoints()).toEqual(['/planetary/templates/tpl-b-line'])
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-b-and-m'])
    expect(wrapper.find('[data-testid="template-confirm"]').exists()).toBe(false)
    expect(store.productions).toEqual(PLAN_PRODUCTIONS)
  })
})
