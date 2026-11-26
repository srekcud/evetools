import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { RANKING_PRODUCT_B } from '../../planetaryPlannerFixtures'
import { makeTemplate } from '../../planetaryTemplatesFixtures'
import { mountTemplates, postedBodies, templateRows } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const SAVED_TEMPLATE = makeTemplate('tpl-new', 'Coolant mix', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 40 }], {}, undefined, '2026-11-26T09:00:00+00:00')

async function mountWithPlan(scenario: Parameters<typeof mountTemplates>[0] = { saved: SAVED_TEMPLATE }) {
  const mounted = await mountTemplates(scenario)
  mounted.store.addProduction(RANKING_PRODUCT_B, 40)
  const input = mounted.wrapper.get('[data-testid="template-name-input"]')
  return { ...mounted, input, inputValue: () => (input.element as HTMLInputElement).value }
}

function postedNames(): string[] {
  return postedBodies().map(body => (body as { name: string }).name)
}

describe('PiPlannerTemplates name field', () => {
  it('empties the field once the plan is saved', async () => {
    const { wrapper, input, inputValue } = await mountWithPlan()
    await input.setValue('Coolant mix')

    await wrapper.get('[data-testid="template-save"]').trigger('click')
    await flushPromises()

    expect(postedNames()).toEqual(['Coolant mix'])
    expect(inputValue()).toBe('')
    expect(wrapper.get('[data-testid="template-save"]').attributes('disabled')).toBeDefined()
  })

  it('keeps the typed name, shows the error and lists no template when the save fails', async () => {
    const { wrapper, store, input, inputValue } = await mountWithPlan()
    const served = vi.mocked(apiRequest).getMockImplementation()
    if (served === undefined) throw new Error('The harness serves no API')
    vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
      if (endpoint === '/planetary/templates' && options?.method === 'POST') throw new Error('limit reached')
      return served(endpoint, options)
    }) as never)
    await input.setValue('Coolant mix')

    await wrapper.get('[data-testid="template-save"]').trigger('click')
    await flushPromises()

    expect(inputValue()).toBe('Coolant mix')
    expect(store.error).toBe(i18n.global.t('pi.planner.errors.templateSave'))
    expect(templateRows(wrapper)).toHaveLength(0)
  })

  it('saves the plan when Enter is pressed in the field and empties it', async () => {
    const { wrapper, input, inputValue } = await mountWithPlan()
    await input.setValue('Coolant mix')

    await input.trigger('keydown.enter')
    await flushPromises()

    expect(postedNames()).toEqual(['Coolant mix'])
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-new'])
    expect(inputValue()).toBe('')
  })

  it('enables the button for a name of 100 characters wrapped in spaces and posts the 100 characters alone', async () => {
    const { wrapper, input } = await mountWithPlan()
    await input.setValue(`  ${'a'.repeat(100)}  `)

    expect(wrapper.get('[data-testid="template-save"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid="template-save"]').trigger('click')
    await flushPromises()

    expect(postedNames()).toEqual(['a'.repeat(100)])
  })

  it('refuses a name of 101 characters wrapped in spaces, on the button as on Enter, and posts nothing', async () => {
    const { wrapper, input, inputValue } = await mountWithPlan()
    await input.setValue(`  ${'a'.repeat(101)}  `)

    expect(wrapper.get('[data-testid="template-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="template-save"]').trigger('click')
    await input.trigger('keydown.enter')
    await flushPromises()

    expect(postedNames()).toEqual([])
    expect(inputValue()).toBe(`  ${'a'.repeat(101)}  `)
  })
})
