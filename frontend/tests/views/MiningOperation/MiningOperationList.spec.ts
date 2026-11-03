import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationList from '@/views/MiningOperation/MiningOperationList.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DORN_WITHOUT_CORPORATION = {
  id: '00000000-0000-4000-8000-000000000263',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationName: '',
} as unknown as User

const ARIA_OF_C1 = {
  id: '00000000-0000-4000-8000-000000000264',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as User

async function mountList(user: User, locale: TestLocale = 'en'): Promise<VueWrapper> {
  useAuthStore().user = user
  const wrapper = mount(MiningOperationList, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

const NEW_OPERATION: Record<TestLocale, string> = { en: 'New operation', fr: 'Nouvelle opération' }

async function openCreation(wrapper: VueWrapper, locale: TestLocale = 'en'): Promise<void> {
  const newOperation = wrapper.findAll('button').find(button => button.text().includes(NEW_OPERATION[locale]))
  expect(newOperation, 'new operation button').toBeDefined()
  await newOperation?.trigger('click')
}

function radio(wrapper: VueWrapper, value: string) {
  const input = wrapper.find(`input[type="radio"][value="${value}"]`)
  expect(input.exists(), `radio ${value}`).toBe(true)
  return input
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue([] as never)
  route.query = {}
})

enableAutoUnmount(afterEach)

describe('MiningOperationList', () => {
  it('disables the corporation visibility for a pilot without known corporation and says why', async () => {
    const wrapper = await mountList(DORN_WITHOUT_CORPORATION)

    await openCreation(wrapper)

    expect(radio(wrapper, 'corporation').attributes('disabled')).toBeDefined()
    expect(radio(wrapper, 'private').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('Unknown corporation: private visibility only.')
  })

  it('offers the corporation visibility to a pilot of a known corporation', async () => {
    const wrapper = await mountList(ARIA_OF_C1)

    await openCreation(wrapper)

    expect(radio(wrapper, 'corporation').attributes('disabled')).toBeUndefined()
  })

  it('shows the weighted time split mode as disabled and coming soon', async () => {
    const wrapper = await mountList(ARIA_OF_C1)

    await openCreation(wrapper)

    expect(radio(wrapper, 'weighted_time').attributes('disabled')).toBeDefined()
    expect(radio(wrapper, 'value_prorata').attributes('disabled')).toBeUndefined()
    expect(radio(wrapper, 'each_for_themselves').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('soon')
  })

  it('tells a pilot without known corporation that only operations shared by link are reachable', async () => {
    route.query = { tab: 'available' }

    const wrapper = await mountList(DORN_WITHOUT_CORPORATION)

    expect(wrapper.text()).toContain('Unknown corporation: only operations shared by link are reachable.')
  })

  it.each(TEST_LOCALES)('shows only %s texts on an empty list of my operations', async (locale) => {
    const wrapper = await mountList(ARIA_OF_C1, locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'miningOperation.title',
      'miningOperation.tabs.mine',
      'miningOperation.tabs.available',
      'miningOperation.list.new',
      'miningOperation.list.emptyMine',
    ])
  })

  it.each(TEST_LOCALES)('shows only %s texts in the creation form', async (locale) => {
    const wrapper = await mountList(ARIA_OF_C1, locale)

    await openCreation(wrapper, locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'miningOperation.create.title',
      'miningOperation.create.label',
      'miningOperation.create.start',
      'miningOperation.create.visibility',
      'miningOperation.create.splitMode',
      'miningOperation.create.characters',
      'miningOperation.create.submit',
      'miningOperation.visibility.private',
      'miningOperation.visibility.corporation',
      'miningOperation.splitMode.value_prorata',
      'miningOperation.splitMode.weighted_time',
      'miningOperation.splitMode.each_for_themselves',
    ])
  })
})
