import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { ApiError, ValidationError, apiRequest } from '@/services/api'
import NotificationPreferencesForm from '@/components/notifications/NotificationPreferencesForm.vue'

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

const WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789xyz'
const REFUSED_HOST_MESSAGE = 'The webhook host must be discord.com'
const TEST_FAILURE_MESSAGE = 'Discord answered with HTTP 404'
const STORED_PREFERENCES = [
  { category: 'planetary', enabled: true, thresholdMinutes: 360, pushEnabled: false },
]

const TEST_IDS = {
  settings: '[data-testid="discord-webhook-settings"]',
  status: '[data-testid="discord-webhook-status"]',
  urlInput: '[data-testid="discord-webhook-url-input"]',
  save: '[data-testid="discord-webhook-save"]',
  test: '[data-testid="discord-webhook-test"]',
  remove: '[data-testid="discord-webhook-remove"]',
  fieldError: '[data-testid="discord-webhook-field-error"]',
  testSuccess: '[data-testid="discord-webhook-test-success"]',
  testFailure: '[data-testid="discord-webhook-test-failure"]',
}

type WebhookRoutes = {
  configured: boolean
  put?: () => unknown
  post?: () => unknown
}

async function mountForm({ configured, put, post }: WebhookRoutes) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === '/me/notification-preferences') return { preferences: STORED_PREFERENCES }
    if (endpoint === '/me/discord-webhook/test') return post === undefined ? null : post()
    if (endpoint === '/me/discord-webhook' && options?.method === 'PUT') return put === undefined ? { configured: true } : put()
    if (endpoint === '/me/discord-webhook' && options === undefined) return { configured }
    throw new Error(`unexpected request ${endpoint}`)
  })
  const wrapper = mount(NotificationPreferencesForm, {
    props: { showCancel: false },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  return wrapper
}

function refusedUrl(): ValidationError {
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'url', message: REFUSED_HOST_MESSAGE }],
    description: `url: ${REFUSED_HOST_MESSAGE}`,
    detail: `url: ${REFUSED_HOST_MESSAGE}`,
  })
}

describe('Discord webhook settings in the notification preferences', () => {
  it('shows an empty password field, a disabled save button and neither test nor remove when no webhook is configured', async () => {
    const wrapper = await mountForm({ configured: false })

    expect(wrapper.find(TEST_IDS.settings).exists()).toBe(true)
    expect(wrapper.get(TEST_IDS.status).attributes('data-configured')).toBe('false')
    const input = wrapper.get(TEST_IDS.urlInput)
    expect(input.attributes('type')).toBe('password')
    expect((input.element as HTMLInputElement).value).toBe('')
    expect(wrapper.get(TEST_IDS.save).attributes('disabled')).toBeDefined()
    expect(wrapper.find(TEST_IDS.test).exists()).toBe(false)
    expect(wrapper.find(TEST_IDS.remove).exists()).toBe(false)
  })

  it('shows the configured state with test and remove, and never prefills the field with the stored url', async () => {
    const wrapper = await mountForm({ configured: true })

    expect(wrapper.find(TEST_IDS.settings).exists()).toBe(true)
    expect(wrapper.get(TEST_IDS.status).attributes('data-configured')).toBe('true')
    expect((wrapper.get(TEST_IDS.urlInput).element as HTMLInputElement).value).toBe('')
    expect(wrapper.find(TEST_IDS.test).exists()).toBe(true)
    expect(wrapper.find(TEST_IDS.remove).exists()).toBe(true)
  })

  it('enables save once a url is typed, sends it, then clears the field and shows the configured state', async () => {
    const wrapper = await mountForm({ configured: false })

    expect(wrapper.find(TEST_IDS.urlInput).exists()).toBe(true)
    await wrapper.get(TEST_IDS.urlInput).setValue(WEBHOOK_URL)
    expect(wrapper.get(TEST_IDS.save).attributes('disabled')).toBeUndefined()
    await wrapper.get(TEST_IDS.save).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith('/me/discord-webhook', {
      method: 'PUT',
      body: JSON.stringify({ url: WEBHOOK_URL }),
    })
    expect((wrapper.get(TEST_IDS.urlInput).element as HTMLInputElement).value).toBe('')
    expect(wrapper.get(TEST_IDS.status).attributes('data-configured')).toBe('true')
  })

  it('shows the backend message under the field when the url is refused and keeps what was typed', async () => {
    const wrapper = await mountForm({
      configured: false,
      put: () => {
        throw refusedUrl()
      },
    })

    expect(wrapper.find(TEST_IDS.urlInput).exists()).toBe(true)
    await wrapper.get(TEST_IDS.urlInput).setValue('https://discord.com.evil.io/api/webhooks/1/x')
    await wrapper.get(TEST_IDS.save).trigger('click')
    await flushPromises()

    expect(wrapper.get(TEST_IDS.fieldError).text()).toBe(REFUSED_HOST_MESSAGE)
    expect((wrapper.get(TEST_IDS.urlInput).element as HTMLInputElement).value).toBe('https://discord.com.evil.io/api/webhooks/1/x')
    expect(wrapper.get(TEST_IDS.status).attributes('data-configured')).toBe('false')
  })

  it('confirms a successful test message', async () => {
    const wrapper = await mountForm({ configured: true })

    expect(wrapper.find(TEST_IDS.test).exists()).toBe(true)
    await wrapper.get(TEST_IDS.test).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith('/me/discord-webhook/test', expect.objectContaining({ method: 'POST' }))
    expect(wrapper.find(TEST_IDS.testSuccess).exists()).toBe(true)
    expect(wrapper.find(TEST_IDS.testFailure).exists()).toBe(false)
  })

  it('shows the backend message when the test message fails', async () => {
    const wrapper = await mountForm({
      configured: true,
      post: () => {
        throw new ApiError(TEST_FAILURE_MESSAGE, 502)
      },
    })

    expect(wrapper.find(TEST_IDS.test).exists()).toBe(true)
    await wrapper.get(TEST_IDS.test).trigger('click')
    await flushPromises()

    expect(wrapper.get(TEST_IDS.testFailure).text()).toContain(TEST_FAILURE_MESSAGE)
    expect(wrapper.find(TEST_IDS.testSuccess).exists()).toBe(false)
  })
})
