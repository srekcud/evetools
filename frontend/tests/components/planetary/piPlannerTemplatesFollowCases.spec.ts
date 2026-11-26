import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { RANKING_PRODUCT_B } from '../../planetaryPlannerFixtures'
import { TEMPLATE_B_AND_M, TEMPLATE_B_LINE, asFollowed, makeTemplate } from '../../planetaryTemplatesFixtures'
import { deletedEndpoints, mountTemplates, postedBodies, templateRows, type TemplatesWrapper } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const SAVED = makeTemplate('tpl-saved', 'Fresh B line', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, undefined, '2026-11-26T09:00:00+00:00')
const LONGEST_NAME = 'n'.repeat(100)
const TOO_LONG_NAME = 'n'.repeat(101)

function followLabels(wrapper: TemplatesWrapper): string[] {
  return templateRows(wrapper).map(row => row.get('[data-testid="template-follow"]').text())
}

function nameField(wrapper: TemplatesWrapper): string {
  return wrapper.get<HTMLInputElement>('[data-testid="template-name-input"]').element.value
}

function failOn(method: string): void {
  const served = vi.mocked(apiRequest).getMockImplementation()
  if (served === undefined) throw new Error('The harness serves no API')
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    if (options?.method === method) throw new Error(`${method} unavailable`)
    return served(endpoint, options)
  }) as never)
}

async function typeNameAndFollow(wrapper: TemplatesWrapper, name: string): Promise<void> {
  await wrapper.get('[data-testid="template-name-input"]').setValue(name)
  await wrapper.get('[data-testid="template-follow-plan"]').trigger('click')
  await flushPromises()
}

describe('PiPlannerTemplates follow buttons edge cases', () => {
  it('keeps Stop following on the followed row when the player stops following another template through the store', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)] })

    await store.unfollowTemplate('tpl-b-and-m')
    await flushPromises()

    expect(followLabels(wrapper)).toEqual(['Follow', 'Stop following'])
    expect(store.followedTemplate?.id).toBe('tpl-b-line')
  })

  it('removes the row and leaves no followed template when the player deletes the followed template and accepts the confirmation', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)] })

    await wrapper.get('[data-testid="template-row"][data-template-id="tpl-b-line"] [data-testid="template-delete"]').trigger('click')
    await wrapper.get('[data-testid="template-confirm-accept"]').trigger('click')
    await flushPromises()

    expect(deletedEndpoints()).toEqual(['/planetary/templates/tpl-b-line'])
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-b-and-m'])
    expect(followLabels(wrapper)).toEqual(['Follow'])
    expect(store.followedTemplate).toBeNull()
  })
})

describe('PiPlannerTemplates follow these plans name', () => {
  it('disables the button for a name of 101 characters, one over the limit', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await wrapper.get('[data-testid="template-name-input"]').setValue(TOO_LONG_NAME)

    expect(wrapper.get('[data-testid="template-follow-plan"]').attributes('disabled')).toBeDefined()
  })

  it('enables the button for a name of 100 characters, the limit', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await wrapper.get('[data-testid="template-name-input"]').setValue(LONGEST_NAME)

    expect(wrapper.get('[data-testid="template-follow-plan"]').attributes('disabled')).toBeUndefined()
  })

  it('empties the name field after the plan is saved and followed', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_LINE], saved: SAVED })
    store.addProduction(RANKING_PRODUCT_B, 120)

    await typeNameAndFollow(wrapper, 'Fresh B line')

    expect(postedBodies().map(body => (body as { name: string }).name)).toEqual(['Fresh B line'])
    expect(nameField(wrapper)).toBe('')
  })

  it('keeps the typed name when the plan cannot be saved', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_LINE], saved: SAVED })
    store.addProduction(RANKING_PRODUCT_B, 120)
    failOn('POST')

    await typeNameAndFollow(wrapper, 'Fresh B line')

    expect(nameField(wrapper)).toBe('Fresh B line')
    expect(store.error).toBe(i18n.global.t('pi.planner.errors.templateSave'))
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-b-line'])
  })

  it('Currently keeps the typed name and the saved template in the list, not followed, when the follow fails after the save (Issue1406)', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [asFollowed(TEMPLATE_B_LINE)], saved: SAVED })
    store.addProduction(RANKING_PRODUCT_B, 120)
    failOn('PATCH')

    await typeNameAndFollow(wrapper, 'Fresh B line')

    expect(nameField(wrapper)).toBe('Fresh B line')
    expect(store.error).toBe(i18n.global.t('pi.planner.errors.templateFollow'))
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-saved', 'tpl-b-line'])
    expect(followLabels(wrapper)).toEqual(['Follow', 'Stop following'])
  })
})
