import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { RANKING_PRODUCT_B } from '../../planetaryPlannerFixtures'
import { TEMPLATE_B_AND_M, TEMPLATE_B_LINE, asFollowed, makeTemplate } from '../../planetaryTemplatesFixtures'
import { mountTemplates, patchedRequests, postedBodies, rowOfTemplate, templateRows, type TemplatesWrapper } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.useRealTimers()
})

const SAVED = makeTemplate('tpl-saved', 'Fresh B line', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, undefined, '2026-11-26T09:00:00+00:00')

function followButton(wrapper: TemplatesWrapper, templateId: string) {
  return rowOfTemplate(wrapper, templateId).get('[data-testid="template-follow"]')
}

function followLabels(wrapper: TemplatesWrapper): string[] {
  return templateRows(wrapper).map(row => row.get('[data-testid="template-follow"]').text())
}

describe('PiPlannerTemplates follow buttons', () => {
  it('offers Follow on a template that is not followed and Stop following on the followed one', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)] })

    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-b-and-m', 'tpl-b-line'])
    expect(followLabels(wrapper)).toEqual(['Follow', 'Stop following'])
    expect(followButton(wrapper, 'tpl-b-and-m').attributes('data-followed')).toBe('false')
    expect(followButton(wrapper, 'tpl-b-line').attributes('data-followed')).toBe('true')
  })

  it('follows a template through its own endpoint and moves the Stop following label from the template followed before to it', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)] })

    await followButton(wrapper, 'tpl-b-and-m').trigger('click')
    await flushPromises()

    expect(patchedRequests()).toEqual([{ endpoint: '/planetary/templates/tpl-b-and-m', body: { followed: true } }])
    expect(followLabels(wrapper)).toEqual(['Stop following', 'Follow'])
    expect(store.followedTemplate?.id).toBe('tpl-b-and-m')
  })

  it('stops following the followed template through its own endpoint and leaves every row on Follow', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)] })

    await followButton(wrapper, 'tpl-b-line').trigger('click')
    await flushPromises()

    expect(patchedRequests()).toEqual([{ endpoint: '/planetary/templates/tpl-b-line', body: { followed: false } }])
    expect(followLabels(wrapper)).toEqual(['Follow', 'Follow'])
    expect(store.followedTemplate).toBeNull()
  })

  it('keeps the labels and shows the error in the store when the patch fails', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)] })
    const served = vi.mocked(apiRequest).getMockImplementation()
    if (served === undefined) throw new Error('The harness serves no API')
    vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
      if (options?.method === 'PATCH') throw new Error('template already gone')
      return served(endpoint, options)
    }) as never)

    await followButton(wrapper, 'tpl-b-and-m').trigger('click')
    await flushPromises()

    expect(followLabels(wrapper)).toEqual(['Follow', 'Stop following'])
    expect(store.error).toBe(i18n.global.t('pi.planner.errors.templateFollow'))
  })

  it('words the buttons in French', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, asFollowed(TEMPLATE_B_LINE)], locale: 'fr' })

    expect(followLabels(wrapper)).toEqual(['Suivre', 'Ne plus suivre'])
  })
})

describe('PiPlannerTemplates follow these plans', () => {
  it('disables the button while the plan has no production, whatever the name', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })
    await wrapper.get('[data-testid="template-name-input"]').setValue('Coolant mix')

    const button = wrapper.get('[data-testid="template-follow-plan"]')

    expect(button.text()).toBe('Follow these plans')
    expect(button.attributes('disabled')).toBeDefined()
  })

  it('enables the button for a plan with a production even with an empty name', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await flushPromises()

    expect(wrapper.get('[data-testid="template-follow-plan"]').attributes('disabled')).toBeUndefined()
  })

  it('saves the plan under the typed name then follows it: the saved template comes first and is the only followed one', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [asFollowed(TEMPLATE_B_LINE)], saved: SAVED })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await wrapper.get('[data-testid="template-name-input"]').setValue('Fresh B line')

    await wrapper.get('[data-testid="template-follow-plan"]').trigger('click')
    await flushPromises()

    expect(postedBodies().map(body => (body as { name: string }).name)).toEqual(['Fresh B line'])
    expect(patchedRequests()).toEqual([{ endpoint: '/planetary/templates/tpl-saved', body: { followed: true } }])
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-saved', 'tpl-b-line'])
    expect(followLabels(wrapper)).toEqual(['Stop following', 'Follow'])
  })

  it('saves the plan under the default name of the day when the name field is empty', async () => {
    i18n.global.locale.value = 'en'
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 10, 26, 10, 30))
    const { wrapper, store } = await mountTemplates({ templates: [], saved: SAVED })
    store.addProduction(RANKING_PRODUCT_B, 120)
    await flushPromises()

    await wrapper.get('[data-testid="template-follow-plan"]').trigger('click')
    await flushPromises()

    expect(postedBodies().map(body => (body as { name: string }).name)).toEqual(['Plan of 26/11/2026'])
  })
})
