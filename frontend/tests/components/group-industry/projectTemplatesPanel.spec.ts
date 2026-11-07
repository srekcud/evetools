import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectTemplatesPanel from '@/components/group-industry/ProjectTemplatesPanel.vue'
import { apiRequest } from '@/services/api'
import type { ProjectTemplate } from '@/stores/group-industry/types'
import { HAMMERHEAD_LINE_FROM_ALICE, SABRE_LINE_OF_BOB } from '../../projectTemplateFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ROW = '[data-testid="project-template-row"]'
const EMPTY_STATE = '[data-testid="project-templates-empty"]'

async function mountPanel(templates: ProjectTemplate[]) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(templates)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProjectTemplatesPanel, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

describe('ProjectTemplatesPanel', () => {
  it('lists a corporation template with its owner and a personal template with the owner-only actions', async () => {
    const wrapper = await mountPanel([HAMMERHEAD_LINE_FROM_ALICE, SABRE_LINE_OF_BOB])

    const [sharedRow, ownRow] = wrapper.findAll(ROW)
    expect(wrapper.findAll(ROW)).toHaveLength(2)
    expect(sharedRow!.get('[data-testid="project-template-name"]').text()).toBe('Hammerhead line')
    expect(sharedRow!.get('[data-testid="project-template-owner"]').text()).toContain('Alice Ferrand')
    expect(sharedRow!.get('[data-testid="project-template-items"]').text()).toContain('2')
    expect(sharedRow!.get('[data-testid="project-template-scope"]').attributes('data-scope')).toBe('corporation')
    expect(sharedRow!.find('[data-testid="project-template-share-toggle"]').exists()).toBe(false)
    expect(sharedRow!.find('[data-testid="project-template-delete"]').exists()).toBe(false)
    expect(ownRow!.get('[data-testid="project-template-name"]').text()).toBe('Sabre line')
    expect(ownRow!.get('[data-testid="project-template-items"]').text()).toContain('1')
    expect(ownRow!.get('[data-testid="project-template-scope"]').attributes('data-scope')).toBe('personal')
    expect(ownRow!.find('[data-testid="project-template-share-toggle"]').exists()).toBe(true)
    expect(ownRow!.find('[data-testid="project-template-delete"]').exists()).toBe(true)
  })

  it('emits the whole template with its items, efficiencies, runs, blacklist and options when Use is clicked', async () => {
    const wrapper = await mountPanel([HAMMERHEAD_LINE_FROM_ALICE, SABRE_LINE_OF_BOB])

    await wrapper.findAll(ROW)[0]!.get('[data-testid="project-template-use"]').trigger('click')

    expect(wrapper.emitted('use')).toEqual([[HAMMERHEAD_LINE_FROM_ALICE]])
  })

  it('shows the empty state and no row when the user has no visible template', async () => {
    const wrapper = await mountPanel([])

    expect(wrapper.find(EMPTY_STATE).exists()).toBe(true)
    expect(wrapper.findAll(ROW)).toHaveLength(0)
  })
})
