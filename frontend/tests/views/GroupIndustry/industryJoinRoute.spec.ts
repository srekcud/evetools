import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import router from '@/router'
import GroupIndustryJoin from '@/views/GroupIndustry/GroupIndustryJoin.vue'
import { groupJoinUrl } from '@/views/GroupIndustry/groupJoinUrl'
import { isNavItemActive, settableModules } from '@/constants/sidebarNavigation'
import { useAuthStore } from '@/stores/auth'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { deferred } from '../../deferred'

const ORIGIN = 'https://evetools.example'
const SHORT_LINK_CODE = 'K7Q2MX'
const route = reactive<{ params: Record<string, string> }>({ params: { code: SHORT_LINK_CODE } })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  route.params = { code: SHORT_LINK_CODE }
  const authStore = useAuthStore()
  authStore.token = 'pilot-token'
  authStore.user = { id: 7 } as never
  await router.push('/ledger')
})

function mountJoin(locale: TestLocale) {
  return mount(GroupIndustryJoin, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
}

describe('industry invitation link', () => {
  it('puts the industry module in the link', () => {
    expect(groupJoinUrl(ORIGIN, SHORT_LINK_CODE)).toBe('https://evetools.example/industry/join/K7Q2MX')
  })

  it('resolves the industry join path to the group industry join route with the short link code', () => {
    const resolved = router.resolve('/industry/join/K7Q2MX')

    expect({ name: resolved.name, code: resolved.params.code }).toEqual({ name: 'group-industry-join', code: SHORT_LINK_CODE })
  })

  it('redirects a link already shared as /join/:code to the industry join link, code and query kept', async () => {
    await router.push('/join/K7Q2MX?ref=discord')
    const { name, params, query, path } = router.currentRoute.value

    expect({ name, params, query, path }).toEqual({
      name: 'group-industry-join',
      params: { code: SHORT_LINK_CODE },
      query: { ref: 'discord' },
      path: '/industry/join/K7Q2MX',
    })
  })

  it('keeps the mining operation invitation on its own link', () => {
    const resolved = router.resolve('/mining/operations/join/AB12CD')

    expect({ name: resolved.name, code: resolved.params.code }).toEqual({ name: 'mining-operation-join', code: 'AB12CD' })
  })

  it('keeps the industry entry active on the industry join link', () => {
    const industryEntry = settableModules.find(entry => entry.id === 'industry')!

    expect(isNavItemActive(industryEntry, '/industry/join/K7Q2MX')).toBe(true)
  })
})

describe('GroupIndustryJoin module label', () => {
  it.each(TEST_LOCALES)('says in %s that the invitation opens an industry project while the request is processed', (locale) => {
    vi.mocked(apiRequest).mockReturnValue(deferred<GroupProject>().promise)

    expect(mountJoin(locale).text()).toContain(messageOf(locale, 'groupIndustry.join.moduleLabel'))
  })

  it.each(TEST_LOCALES)('keeps the %s module label once the request waits for approval', async (locale) => {
    vi.mocked(apiRequest).mockResolvedValue({ id: 'project-219', myStatus: 'pending' } as GroupProject)

    const wrapper = mountJoin(locale)
    await flushPromises()

    expect(wrapper.text()).toContain(messageOf(locale, 'groupIndustry.join.moduleLabel'))
  })

  it('names the industry module differently from the mining one in each locale', () => {
    for (const locale of TEST_LOCALES) {
      expect(messageOf(locale, 'groupIndustry.join.moduleLabel')).not.toMatch(/mining|minage/i)
    }
  })
})
