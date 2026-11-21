import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hull-run'
const REFRESH_PATH = '/me/corporation/assets/refresh'
const VERIFICATION_PATH = `/industry/projects/${PROJECT_ID}/container-verification`

function answerRefreshWith(refreshAnswer: Record<string, unknown>): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === REFRESH_PATH) return refreshAnswer as never
    if (endpoint === VERIFICATION_PATH) return [] as never
    return [] as never
  })
}

function mountBanner() {
  setActivePinia(createPinia())
  answerRefreshWith({ status: 'pending' })
  return mount(ContainerBanner, {
    props: { containerNames: ['Build'], projectId: PROJECT_ID, canVerify: true },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

function setCorporationAssetsSync(status: 'started' | 'completed' | 'error'): void {
  useSyncStore().syncStatus['corporation-assets'] = {
    syncType: 'corporation-assets',
    status,
    progress: null,
    message: null,
    data: null,
    timestamp: '2026-11-21T21:00:00+00:00',
  }
}

async function clickRefresh(refreshAnswer: Record<string, unknown>) {
  const wrapper = mountBanner()
  answerRefreshWith(refreshAnswer)
  await wrapper.get('[data-testid="container-refresh-assets"]').trigger('click')
  await flushPromises()
  return wrapper
}

function verificationRequests(): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === VERIFICATION_PATH).length
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContainerBanner refresh of the corporation assets', () => {
  it('stops waiting and verifies the container when the assets are already up to date', async () => {
    const wrapper = await clickRefresh({ status: 'up_to_date', syncedAt: '2026-11-13T12:00:00+00:00', nextRefreshAt: '2026-11-13T13:00:00+00:00' })

    expect([
      wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled'),
      wrapper.find('[data-testid="container-refresh-error"]').exists(),
      verificationRequests(),
    ]).toEqual([undefined, false, 1])
  })

  it('keeps waiting without an error while a sync is already running', async () => {
    const wrapper = await clickRefresh({ status: 'in_progress' })

    expect([
      wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled'),
      wrapper.find('[data-testid="container-refresh-error"]').exists(),
      verificationRequests(),
    ]).toEqual(['', false, 0])
  })

  it('verifies the container once when the sync already running completes', async () => {
    const wrapper = await clickRefresh({ status: 'in_progress' })

    setCorporationAssetsSync('completed')
    await flushPromises()

    expect([
      wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled'),
      wrapper.find('[data-testid="container-refresh-error"]').exists(),
      verificationRequests(),
    ]).toEqual([undefined, false, 1])
  })

  it('shows the refresh error when the sync already running fails', async () => {
    const wrapper = await clickRefresh({ status: 'in_progress' })

    setCorporationAssetsSync('error')
    await flushPromises()

    expect([
      wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled'),
      wrapper.find('[data-testid="container-refresh-error"]').exists(),
      verificationRequests(),
    ]).toEqual([undefined, true, 0])
  })

  it('ignores a sync that ends when no refresh was requested', async () => {
    const wrapper = mountBanner()

    setCorporationAssetsSync('completed')
    await flushPromises()

    expect([
      wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled'),
      verificationRequests(),
    ]).toEqual([undefined, 0])
  })

  it('keeps waiting for the sync events while the sync is pending', async () => {
    const wrapper = await clickRefresh({ status: 'pending' })

    expect(wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled')).toBeDefined()
  })
})
