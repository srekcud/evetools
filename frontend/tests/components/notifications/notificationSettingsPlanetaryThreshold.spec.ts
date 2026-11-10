import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
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

const BaseModalStub = { template: '<div><slot name="title" title-id="settings-title" /><slot /></div>' }

async function mountSettings() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PUT') return null
    if (endpoint === '/me/notification-preferences') return { preferences: STORED_PREFERENCES }
    throw new Error(`unexpected request ${endpoint}`)
  })
  const wrapper = mount(NotificationSettings, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: { BaseModal: BaseModalStub },
    },
  })
  await flushPromises()
  return wrapper
}

function sentPlanetaryThreshold(): unknown {
  const saveCall = vi.mocked(apiRequest).mock.calls.find(([, options]) => options?.method === 'PUT')
  if (saveCall === undefined) throw new Error('preferences were not saved')
  const body = JSON.parse(String(saveCall[1]?.body)) as { preferences: { category: string; thresholdMinutes: unknown }[] }
  const planetary = body.preferences.find(preference => preference.category === 'planetary')
  if (planetary === undefined) throw new Error('planetary preference missing from the request')
  return planetary.thresholdMinutes
}

describe('NotificationSettings planetary threshold', () => {
  it('bounds the threshold input between 15 minutes and one week', async () => {
    const wrapper = await mountSettings()

    const thresholdInputs = wrapper.findAll('input[type="number"]')

    expect(thresholdInputs).toHaveLength(1)
    expect(thresholdInputs[0].attributes('min')).toBe('15')
    expect(thresholdInputs[0].attributes('max')).toBe('10080')
  })

  it('never sends an empty string when the threshold field is cleared', async () => {
    const wrapper = await mountSettings()

    await wrapper.find('input[type="number"]').setValue('')
    const saveButton = wrapper.findAll('button').find(button => button.text() === en.notificationHub.settingsModal.save)
    await saveButton?.trigger('click')
    await flushPromises()

    expect([null, 1440]).toContain(sentPlanetaryThreshold())
  })
})
