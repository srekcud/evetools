import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import ContractsTab from '@/components/assets/ContractsTab.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const CONTRACTS_LABEL = 'Contracts'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountAssetsAt(query: LocationQuery) {
  route.query = query
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as unknown as User
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ items: [] }), { status: 200 })))
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
        ContractsTab: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('Inventory page without the contracts tab', () => {
  it('offers no contracts button', async () => {
    const wrapper = await mountAssetsAt({})

    expect(wrapper.findAll('button').filter(button => button.text() === CONTRACTS_LABEL)).toHaveLength(0)
  })

  it('has no tab bar of its own beyond the wealth module bar', async () => {
    const wrapper = await mountAssetsAt({})

    expect(wrapper.findAll('nav')).toHaveLength(1)
  })

  it('does not mount the contracts list when the address still carries tab=contracts', async () => {
    const wrapper = await mountAssetsAt({ tab: 'contracts' })

    expect(wrapper.findAllComponents(ContractsTab)).toHaveLength(0)
  })
})
