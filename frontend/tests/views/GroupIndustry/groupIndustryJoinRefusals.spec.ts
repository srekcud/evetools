import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryJoin from '@/views/GroupIndustry/GroupIndustryJoin.vue'
import { ApiError, apiRequest } from '@/services/api'

const route = reactive<{ params: Record<string, string> }>({ params: { code: 'K7Q2MX' } })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NOT_FOUND_API_MESSAGE = 'Project not found'
const NOT_OPEN_YET_API_MESSAGE = 'This project is not open to new members yet'
const CLOSED_API_MESSAGE = 'This project is no longer accepting new members'

const NOT_AVAILABLE = {
  en: 'This invitation link is not available. Ask the project owner for a working link.',
  fr: 'Ce lien d\'invitation n\'est pas disponible. Demandez un lien valide au responsable du projet.',
}
const NOT_OPEN_YET = {
  en: 'This project is not open yet. Ask its owner to publish it, then try again.',
  fr: 'Ce projet n\'est pas encore ouvert. Demandez à son responsable de le publier, puis réessayez.',
}
const CLOSED = {
  en: 'This project is closed to new members.',
  fr: 'Ce projet n\'accepte plus de nouveaux membres.',
}

async function mountJoinRefusedWith(refusal: Error, locale: 'en' | 'fr'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockRejectedValue(refusal)
  const wrapper = mount(GroupIndustryJoin, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  route.params = { code: 'K7Q2MX' }
})

enableAutoUnmount(afterEach)

describe('GroupIndustryJoin refusals', () => {
  it.each([
    ['en', NOT_AVAILABLE.en, NOT_FOUND_API_MESSAGE, new ApiError(NOT_FOUND_API_MESSAGE, 404)],
    ['fr', NOT_AVAILABLE.fr, NOT_FOUND_API_MESSAGE, new ApiError(NOT_FOUND_API_MESSAGE, 404)],
    ['en', NOT_OPEN_YET.en, NOT_OPEN_YET_API_MESSAGE, new ApiError(NOT_OPEN_YET_API_MESSAGE, 400)],
    ['fr', NOT_OPEN_YET.fr, NOT_OPEN_YET_API_MESSAGE, new ApiError(NOT_OPEN_YET_API_MESSAGE, 400)],
    ['en', CLOSED.en, CLOSED_API_MESSAGE, new ApiError(CLOSED_API_MESSAGE, 400)],
    ['fr', CLOSED.fr, CLOSED_API_MESSAGE, new ApiError(CLOSED_API_MESSAGE, 400)],
  ] as const)('shows the %s text %j instead of the raw API message %j', async (locale, translated, raw, refusal) => {
    const text = (await mountJoinRefusedWith(refusal, locale)).text()

    expect(text).toContain(translated)
    expect(text).not.toContain(raw)
  })

  it('keeps the raw message of an error that is not a known refusal', async () => {
    const text = (await mountJoinRefusedWith(new ApiError('Something unexpected', 500), 'en')).text()

    expect(text).toContain('Something unexpected')
  })
})
