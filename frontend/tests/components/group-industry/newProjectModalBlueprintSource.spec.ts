import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import type { ProjectPrefillItem } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type SourcedPrefillItem = ProjectPrefillItem & { blueprintSource: 'owned' | 'assumed' }

const DRAKE_FROM_OWNED_BLUEPRINT: SourcedPrefillItem = { typeId: 24698, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'owned' }
const HAMMERHEAD_FROM_ASSUMPTION: SourcedPrefillItem = { typeId: 2185, typeName: 'Hammerhead II', meLevel: 2, teLevel: 4, runs: 1, blueprintSource: 'assumed' }
const VEXOR_FROM_LIBRARY_TAB: ProjectPrefillItem = { typeId: 626, typeName: 'Vexor', meLevel: 6, teLevel: 12, runs: 25 }

const sourceBadge = (typeId: number): string => `[data-testid="item-blueprint-source-${typeId}"]`

async function mountModal(prefillItems: ProjectPrefillItem[]) {
  setActivePinia(createPinia())
  const wrapper = mount(NewProjectModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: { show: false, prefillItems },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => Promise.resolve(
    endpoint === '/industry/project-references' ? { rows: [] } : { id: 'project-1003', status: 'draft' },
  ))
})

describe('NewProjectModal blueprint source of pre-filled items (issue 1003)', () => {
  it('marks each line as coming from an owned blueprint or from the scanner assumption', async () => {
    const wrapper = await mountModal([DRAKE_FROM_OWNED_BLUEPRINT, HAMMERHEAD_FROM_ASSUMPTION])

    expect(wrapper.find(sourceBadge(24698)).exists()).toBe(true)
    expect(wrapper.find(sourceBadge(24698)).attributes('data-blueprint-source')).toBe('owned')
    expect(wrapper.find(sourceBadge(2185)).exists()).toBe(true)
    expect(wrapper.find(sourceBadge(2185)).attributes('data-blueprint-source')).toBe('assumed')
  })

  it('shows no mark on a line pre-filled without a blueprint source', async () => {
    const wrapper = await mountModal([VEXOR_FROM_LIBRARY_TAB])

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.find(sourceBadge(626)).exists()).toBe(false)
  })
})
