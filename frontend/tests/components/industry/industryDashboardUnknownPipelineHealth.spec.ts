import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountDashboard() {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function pipelineHealthCard(wrapper: Awaited<ReturnType<typeof mountDashboard>>) {
  const card = wrapper.findAll('div.rounded-xl').find(div => div.find('p').exists() && div.get('p').text() === en.industry.dashboard.pipelineHealth)
  if (!card) throw new Error('No pipeline health card')
  return card
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/stockpile-status') return Promise.reject(new Error('stockpile status unavailable'))
    return new Promise(() => {})
  })
})

describe('IndustryDashboard pipeline health while the stockpile status is unknown', () => {
  it('shows the pipeline health as unknown with the unknown-value tooltip and no percent', async () => {
    const wrapper = await mountDashboard()

    const card = pipelineHealthCard(wrapper)
    expect(card.get('[title]').attributes('title')).toBe('Unknown value')
    expect(card.get('[title]').text()).toBe('—')
    expect(card.text()).not.toContain('%')
  })

  it('shows a neutral pipeline health without any red, amber or green colour', async () => {
    const wrapper = await mountDashboard()

    const card = pipelineHealthCard(wrapper)
    expect(card.html()).not.toMatch(/text-(red|amber|emerald)-400|ring-glow-|#f87171|#fbbf24|#34d399/)
  })
})
