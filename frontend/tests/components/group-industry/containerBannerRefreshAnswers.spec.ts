import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { apiRequest } from '@/services/api'

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

async function clickRefresh(refreshAnswer: Record<string, unknown>) {
  setActivePinia(createPinia())
  answerRefreshWith(refreshAnswer)
  const wrapper = mount(ContainerBanner, {
    props: { containerNames: ['Build'], projectId: PROJECT_ID, canVerify: true },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
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

  it('stops waiting without an error when a sync is already running', async () => {
    const wrapper = await clickRefresh({ status: 'in_progress' })

    expect([
      wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled'),
      wrapper.find('[data-testid="container-refresh-error"]').exists(),
      verificationRequests(),
    ]).toEqual([undefined, false, 0])
  })

  it('keeps waiting for the sync events while the sync is pending', async () => {
    const wrapper = await clickRefresh({ status: 'pending' })

    expect(wrapper.get('[data-testid="container-refresh-assets"]').attributes('disabled')).toBeDefined()
  })
})
