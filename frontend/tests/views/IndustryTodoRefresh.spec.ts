import { defineComponent, h } from 'vue'
import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import IndustryTodo from '@/views/IndustryTodo.vue'
import { EXAMPLE_ACTIONS, ISHTAR_PROJECT_ID } from '../todoPlaceFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

enableAutoUnmount(afterEach)

describe('IndustryTodo refresh', () => {
  it('reads the actions again when a project sync notification arrives', async () => {
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockResolvedValue(EXAMPLE_ACTIONS as never)
    setActivePinia(createPinia())
    const syncStore = useSyncStore()
    mount(IndustryTodo, {
      global: {
        plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
        stubs: { MainLayout: MainLayoutStub, IndustryModuleTabs: true, RouterLink: RouterLinkStub },
      },
    })
    await flushPromises()
    const readsAtMount = vi.mocked(apiRequest).mock.calls.length

    syncStore.syncStatus['industry-project'] = {
      syncType: 'industry-project',
      status: 'notification' as never,
      progress: null,
      message: 'industry-project notification',
      data: { projectId: ISHTAR_PROJECT_ID },
      timestamp: '2026-11-22T10:05:00+00:00',
    }
    await flushPromises()

    expect({ readsAtMount, readsAfter: vi.mocked(apiRequest).mock.calls.length }).toEqual({ readsAtMount: 1, readsAfter: 2 })
  })
})
