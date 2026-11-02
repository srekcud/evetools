import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryJoin from '@/views/GroupIndustry/GroupIndustryJoin.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { expectNoneOf, expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { deferred } from '../../deferred'

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

const PENDING_PROJECT = { id: 'project-219', myStatus: 'pending' } as GroupProject

const FORMER_HARDCODED_TEXTS = [
  'Joining Project...',
  'Please wait while we process your request',
  'Request Sent',
  'Your join request has been sent',
  'Unable to Join',
  'Back to Group Industry',
  'Invalid join link',
  'Failed to join project',
]

const JOIN_TEXT_KEYS = {
  loading: ['groupIndustry.join.joining', 'groupIndustry.join.joiningWait'],
  pending: ['groupIndustry.join.requestSent', 'groupIndustry.join.requestSentDescription', 'groupIndustry.join.backToGroupIndustry'],
  failure: ['groupIndustry.join.unableToJoin', 'groupIndustry.join.backToGroupIndustry'],
}

function mountJoin(locale: TestLocale): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(GroupIndustryJoin, {
    global: {
      plugins: [i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  route.params = { code: 'K7Q2MX' }
})

enableAutoUnmount(afterEach)

describe('GroupIndustryJoin locale', () => {
  it.each(TEST_LOCALES)('shows only %s texts while the request is being processed', (locale) => {
    const request = deferred<GroupProject>()
    vi.mocked(apiRequest).mockReturnValue(request.promise)

    const text = mountJoin(locale).text()

    expectOnlyLocaleTexts(text, locale, JOIN_TEXT_KEYS.loading)
    if (locale === 'fr') expectNoneOf(text, FORMER_HARDCODED_TEXTS)
  })

  it.each(TEST_LOCALES)('shows only %s texts once the request waits for approval', async (locale) => {
    vi.mocked(apiRequest).mockResolvedValue(PENDING_PROJECT)

    const wrapper = mountJoin(locale)
    await flushPromises()

    expectOnlyLocaleTexts(wrapper.text(), locale, JOIN_TEXT_KEYS.pending)
    if (locale === 'fr') expectNoneOf(wrapper.text(), FORMER_HARDCODED_TEXTS)
  })

  it.each(TEST_LOCALES)('shows the %s invalid link message when the link has no code', async (locale) => {
    route.params = { code: '' }

    const wrapper = mountJoin(locale)
    await flushPromises()

    expectOnlyLocaleTexts(wrapper.text(), locale, [...JOIN_TEXT_KEYS.failure, 'groupIndustry.join.invalidLink'])
    if (locale === 'fr') expectNoneOf(wrapper.text(), FORMER_HARDCODED_TEXTS)
  })

  it.each(TEST_LOCALES)('shows the %s failure message when the join request fails without a message', async (locale) => {
    vi.mocked(apiRequest).mockRejectedValue('Gateway timeout (fictif)')

    const wrapper = mountJoin(locale)
    await flushPromises()

    expectOnlyLocaleTexts(wrapper.text(), locale, [...JOIN_TEXT_KEYS.failure, 'groupIndustry.join.joinFailed'])
    if (locale === 'fr') expectNoneOf(wrapper.text(), FORMER_HARDCODED_TEXTS)
  })
})
