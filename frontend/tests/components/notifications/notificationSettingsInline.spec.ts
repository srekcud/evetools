import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import BaseModal from '@/components/common/BaseModal.vue'
import NotificationSettings from '@/components/notifications/NotificationSettings.vue'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

vi.mock('@/composables/usePushNotifications', () => ({
  usePushNotifications: () => ({
    isSupported: { value: true },
    permission: { value: 'granted' },
    requestPermission: vi.fn(),
    subscribe: vi.fn(),
  }),
}))

const STORED_PREFERENCES = [
  { category: 'planetary', enabled: true, thresholdMinutes: 360, pushEnabled: false },
  { category: 'price', enabled: true, thresholdMinutes: null, pushEnabled: false },
]

async function mountSettings(props: Record<string, unknown>) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/notification-preferences') return { preferences: STORED_PREFERENCES }
    throw new Error(`unexpected request ${endpoint}`)
  })
  const wrapper = mount(NotificationSettings, {
    props,
    attachTo: document.body,
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('NotificationSettings inline', () => {
  it('renders the preferences without a modal and without a close button when inline', async () => {
    const wrapper = await mountSettings({ inline: true })

    expect(wrapper.findComponent(BaseModal).exists()).toBe(false)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(wrapper.find('button[aria-label="Close"]').exists()).toBe(false)
    expect(wrapper.findAll('button').map(button => button.text())).not.toContain('Close')
    expect((wrapper.get('input[type="number"]').element as HTMLInputElement).value).toBe('360')
  })

  it('keeps the modal with its close button when not inline, as opened from the bell', async () => {
    const wrapper = await mountSettings({})

    expect(wrapper.findComponent(BaseModal).exists()).toBe(true)
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    expect(document.querySelector('button[aria-label="Close"]')).not.toBeNull()
  })
})
