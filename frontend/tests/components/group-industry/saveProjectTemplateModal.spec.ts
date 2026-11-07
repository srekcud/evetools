import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SaveProjectTemplateModal from '@/components/group-industry/SaveProjectTemplateModal.vue'
import { apiRequest } from '@/services/api'
import { SABRE_LINE_OF_BOB } from '../../projectTemplateFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NAME_INPUT = '[data-testid="template-name-input"]'
const SHARE_CHECKBOX = '[data-testid="template-share-checkbox"]'
const SUBMIT_BUTTON = '[data-testid="template-save-submit"]'
const SABRE_BATCH_ID = 'project-sabre-batch'

function mountModal() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(SABRE_LINE_OF_BOB)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(SaveProjectTemplateModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true, projectId: SABRE_BATCH_ID, defaultName: 'Sabre batch' },
  })
}

describe('SaveProjectTemplateModal', () => {
  it('prefills the name with the project name and posts the project, the name and the corporation sharing', async () => {
    const wrapper = mountModal()

    expect((wrapper.get(NAME_INPUT).element as HTMLInputElement).value).toBe('Sabre batch')
    expect((wrapper.get(SHARE_CHECKBOX).element as HTMLInputElement).checked).toBe(false)
    await wrapper.get(SHARE_CHECKBOX).setValue(true)
    await wrapper.get(SUBMIT_BUTTON).trigger('click')
    await flushPromises()

    const [endpoint, options] = vi.mocked(apiRequest).mock.calls[0]!
    expect(endpoint).toBe('/industry/project-templates')
    expect(JSON.parse(String(options!.body))).toEqual({ projectId: SABRE_BATCH_ID, name: 'Sabre batch', sharedWithCorporation: true })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('sends nothing while the name is empty', async () => {
    const wrapper = mountModal()

    await wrapper.get(NAME_INPUT).setValue('')
    await wrapper.get(SUBMIT_BUTTON).trigger('click')
    await flushPromises()

    expect(apiRequest).not.toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toBeUndefined()
  })
})
