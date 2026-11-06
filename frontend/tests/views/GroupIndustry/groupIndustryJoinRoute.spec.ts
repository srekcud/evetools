import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryJoin from '@/views/GroupIndustry/GroupIndustryJoin.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'

const route = reactive<{ params: Record<string, string> }>({ params: { code: 'K7Q2MX' } })
const replace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ACCEPTED_PROJECT = { id: 'project-219', myStatus: 'accepted' } as GroupProject

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  replace.mockReset()
})

describe('GroupIndustryJoin once the membership is accepted', () => {
  it('opens the project on the industry project route', async () => {
    vi.mocked(apiRequest).mockResolvedValue(ACCEPTED_PROJECT)

    mount(GroupIndustryJoin, {
      global: {
        plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
        stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, 'router-link': { template: '<a><slot /></a>' } },
      },
    })
    await flushPromises()

    expect(replace.mock.calls).toEqual([[{ name: 'industry-project', params: { id: 'project-219' } }]])
  })
})
