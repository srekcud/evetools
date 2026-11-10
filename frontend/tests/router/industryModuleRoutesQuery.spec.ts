import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import { MODULE_LAST_PAGE_STORAGE_KEY } from '@/composables/useModuleLastPage'

const PROJECT_ID = '0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a40'
const NEUTRAL_PATH = '/dashboard'

beforeAll(() => {
  vi.setConfig({ testTimeout: 30000 })
})

beforeEach(async () => {
  localStorage.clear()
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.token = 'pilot-token'
  authStore.user = { id: 7 } as never
  await router.push(NEUTRAL_PATH)
})

async function landingPathOf(oldUrl: string): Promise<string> {
  await router.push(oldUrl)
  return router.currentRoute.value.fullPath
}

describe('industry module routes, query and memory', () => {
  it.each([
    ['analysis', 'industry-analysis', '/industry/analysis'],
    ['projects', 'industry-projects', '/industry/projects'],
    ['production', 'industry-production', '/industry/production'],
    ['workshop', 'industry', '/industry'],
    ['corporation hangars', 'industry-hangars', '/industry/hangars'],
  ])('names the %s page and gives it its path', (_page, routeName, expectedPath) => {
    expect(router.resolve({ name: routeName }).path).toBe(expectedPath)
  })

  it.each([
    ['margins', '/industry?tab=margins', '/industry/analysis?tab=margins'],
    ['batch scan', '/industry?tab=batch&origin=dashboard', '/industry/analysis?tab=batch&origin=dashboard'],
    ['buy vs build with a type', '/industry?tab=buy-vs-build&typeId=587', '/industry/analysis?tab=buy-vs-build&typeId=587'],
    ['pivot', '/industry?tab=pivot&typeId=638', '/industry/analysis?tab=pivot&typeId=638'],
    ['slots', '/industry?tab=slots', '/industry/production?tab=slots'],
    ['stockpile', '/industry?tab=stockpile&origin=dashboard', '/industry/production?tab=stockpile&origin=dashboard'],
    ['blueprints', '/industry?tab=blueprints', '/industry/production?tab=blueprints'],
  ])('redirects the former %s tab to its page and keeps the query', async (_tab, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it.each([
    ['workshop', '/industry?tab=workshop', '/industry'],
    ['dashboard', '/industry?tab=dashboard', '/industry'],
    ['dashboard with another parameter', '/industry?tab=dashboard&origin=notification', '/industry?origin=notification'],
  ])('sends the former %s tab to the workshop without a tab', async (_tab, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it.each([
    ['the workshop without tab', '/industry'],
    ['the workshop with a type', '/industry?typeId=587'],
    ['the analysis page', '/industry/analysis?tab=pivot&typeId=587'],
    ['the skills page', '/industry/skills'],
    ['the corporation hangars page', '/industry/hangars'],
  ])('leaves %s where it is', async (_case, url) => {
    expect(await landingPathOf(url)).toBe(url)
  })

  it.each([
    ['the list', '/group-industry', '/industry/projects'],
    ['the available tab of the list', '/group-industry?tab=available', '/industry/projects?tab=available'],
    ['a project detail', `/group-industry/${PROJECT_ID}?tab=sales`, `/industry/projects/${PROJECT_ID}?tab=sales`],
  ])('redirects the former group industry link to %s', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it('remembers the projects page of the module when a project detail is opened', async () => {
    await router.push(`/industry/projects/${PROJECT_ID}`)

    expect(JSON.parse(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY) ?? 'null')).toEqual({ industry: '/industry/projects' })
  })

  it('remembers nothing when the skills page, the corporation hangars page or an invitation is opened', async () => {
    await router.push('/industry/skills')
    await router.push('/industry/hangars')
    await router.push('/industry/join/AB12CD')

    expect(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY)).toBeNull()
  })
})
