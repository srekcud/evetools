import { createPinia, setActivePinia } from 'pinia'
import { ApiError, ValidationError, apiRequest } from '@/services/api'
import { useDiscordWebhookStore } from '@/stores/discordWebhook'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789xyz'
const REFUSED_HOST_MESSAGE = 'The webhook host must be discord.com'

function refusedUrl(): ValidationError {
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'url', message: REFUSED_HOST_MESSAGE }],
    description: `url: ${REFUSED_HOST_MESSAGE}`,
    detail: `url: ${REFUSED_HOST_MESSAGE}`,
  })
}

describe('discordWebhook store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockReset()
  })

  it('reads whether a Discord webhook is configured from the status endpoint', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ configured: true })
    const store = useDiscordWebhookStore()

    expect(store.configured).toBeNull()
    await store.fetchStatus()

    expect(apiRequest).toHaveBeenCalledWith('/me/discord-webhook')
    expect(store.configured).toBe(true)
    expect(store.isLoading).toBe(false)
  })

  it('sends the webhook url in the PUT body and becomes configured', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ configured: true })
    const store = useDiscordWebhookStore()

    const saved = await store.save(WEBHOOK_URL)

    expect(apiRequest).toHaveBeenCalledWith('/me/discord-webhook', {
      method: 'PUT',
      body: JSON.stringify({ url: WEBHOOK_URL }),
    })
    expect(saved).toBe(true)
    expect(store.configured).toBe(true)
    expect(store.fieldError).toBeNull()
  })

  it('keeps the backend message of a refused url as field error and stays not configured', async () => {
    vi.mocked(apiRequest).mockRejectedValue(refusedUrl())
    const store = useDiscordWebhookStore()
    store.configured = false

    const saved = await store.save('https://discord.com.evil.io/api/webhooks/1/x')

    expect(saved).toBe(false)
    expect(store.fieldError).toBe(REFUSED_HOST_MESSAGE)
    expect(store.error).toBeNull()
    expect(store.configured).toBe(false)
  })

  it('removes the webhook with a DELETE and becomes not configured', async () => {
    vi.mocked(apiRequest).mockResolvedValue(null)
    const store = useDiscordWebhookStore()
    store.configured = true

    await store.remove()

    expect(apiRequest).toHaveBeenCalledWith('/me/discord-webhook', { method: 'DELETE' })
    expect(store.configured).toBe(false)
  })

  it('returns true when the test message is sent and false with the backend message when Discord refuses it', async () => {
    const store = useDiscordWebhookStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(null)

    expect(await store.sendTest()).toBe(true)
    expect(apiRequest).toHaveBeenCalledWith('/me/discord-webhook/test', expect.objectContaining({ method: 'POST' }))
    expect(store.error).toBeNull()

    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('Discord answered with HTTP 404', 502))

    expect(await store.sendTest()).toBe(false)
    expect(store.error).toBe('Discord answered with HTTP 404')
  })
})
