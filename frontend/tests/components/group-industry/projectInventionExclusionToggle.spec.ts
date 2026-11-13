import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectInventionExclusionToggle from '@/components/group-industry/ProjectInventionExclusionToggle.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
const TOGGLE = '[data-testid="project-invention-exclusion-toggle"]'

type Overrides = Partial<{ status: string, canEdit: boolean, excludesInvention: boolean }>

async function mountToggle(overrides: Overrides = {}): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(ProjectInventionExclusionToggle, {
    props: { projectId: PROJECT_ID, status: 'draft', canEdit: true, excludesInvention: false, ...overrides },
    global: { plugins: [i18n] },
  })
  await flushPromises()
  return wrapper
}

function state(wrapper: VueWrapper): { checked: boolean | 'absent', disabled: boolean | 'absent' } {
  const found = wrapper.find(TOGGLE)
  if (!found.exists()) return { checked: 'absent', disabled: 'absent' }
  const input = found.element as HTMLInputElement
  return { checked: input.checked, disabled: input.disabled }
}

function patches(): Array<Record<string, unknown>> {
  return vi.mocked(apiRequest).mock.calls
    .filter(([path, options]) => path === PROJECT_PATH && options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({ id: PROJECT_ID })
})

describe('project invention exclusion toggle (issue 806)', () => {
  it('shows the current value with its help sentence and patches only excludesInvention on a draft the user can edit, then tells the page', async () => {
    const wrapper = await mountToggle({ excludesInvention: false })
    const initial = state(wrapper)

    if (wrapper.find(TOGGLE).exists()) await wrapper.get(TOGGLE).setValue(true)
    await flushPromises()

    expect(initial).toEqual({ checked: false, disabled: false })
    expect(wrapper.text()).toContain(i18n.global.t('groupIndustry.inventionExclusion.help'))
    expect(patches()).toEqual([{ excludesInvention: true }])
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('is read-only once the project is not a draft or when the user cannot edit it, and sends nothing', async () => {
    const published = await mountToggle({ status: 'published', excludesInvention: true })
    const participant = await mountToggle({ canEdit: false, excludesInvention: true })

    expect([state(published), state(participant)]).toEqual([
      { checked: true, disabled: true },
      { checked: true, disabled: true },
    ])
    expect(patches()).toEqual([])
  })

  it('shows the refusal of the API and tells nothing to the page', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('The production plan has 301 BOM lines, above the limit of 300 BOM lines per project.'))
    const wrapper = await mountToggle()

    if (wrapper.find(TOGGLE).exists()) await wrapper.get(TOGGLE).setValue(true)
    await flushPromises()

    const alert = wrapper.find('[data-testid="project-invention-exclusion-error"]')
    expect(alert.exists() ? alert.text() : 'absent').toContain('The production plan has 301 BOM lines, above the limit of 300 BOM lines per project.')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })
})
