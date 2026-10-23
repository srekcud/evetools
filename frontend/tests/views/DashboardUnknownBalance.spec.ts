import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Dashboard from '@/views/Dashboard.vue'

const KNOWN_BALANCE = 1500000.5

const walletsWithOneUnknownBalance = {
  '@context': '/api/contexts/Wallet',
  '@id': '/api/me/wallets',
  '@type': 'Wallet',
  id: 'current',
  totalBalance: KNOWN_BALANCE,
  incomplete: true,
  wallets: [
    { characterId: '00000000-0000-4000-8000-000000231001', characterName: 'Ilyra Castell', isMain: true, balance: KNOWN_BALANCE },
    { characterId: '00000000-0000-4000-8000-000000231002', characterName: 'Doran Vex', isMain: false, balance: null },
  ],
}

async function mountDashboard() {
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(walletsWithOneUnknownBalance), { status: 200 })))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Dashboard, {
    global: {
      plugins: [pinia, i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

describe('Dashboard wallet total with an unknown balance', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the partial total line counting the one unknown balance', async () => {
    const wrapper = await mountDashboard()

    const text = wrapper.text().replace(/\s+/g, ' ')

    expect(text).toContain('Partial total: 1 unknown balance')
    expect(text).toContain('1.50 M')
  })
})
