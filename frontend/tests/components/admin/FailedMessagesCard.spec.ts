import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import FailedMessagesCard from '@/components/admin/FailedMessagesCard.vue'
import { ApiError, apiRequest } from '@/services/api'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, expectOnlyLocaleTexts, messageOf, type TestLocale } from '../../localeTexts'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FAILED_MESSAGES_PATH = '/admin/failed-messages'
const ASSETS_CLASS = 'App\\Message\\SyncCharacterAssets'
const BLUEPRINTS_CLASS = 'App\\Message\\SyncBlueprints'
const ASSETS_MESSAGE_ID = '42'
const BLUEPRINTS_MESSAGE_ID = '17'

interface FailedMessageListing {
  total: number
  classes: Array<{ messageClass: string; count: number }>
  messages: Array<{
    id: string
    messageClass: string
    exceptionClass: string | null
    exceptionMessage: string | null
    failedAt: string | null
    retryCount: number | null
  }>
}

const EMPTY_LISTING: FailedMessageListing = { total: 0, classes: [], messages: [] }

const FILLED_LISTING: FailedMessageListing = {
  total: 4,
  classes: [
    { messageClass: ASSETS_CLASS, count: 3 },
    { messageClass: BLUEPRINTS_CLASS, count: 1 },
  ],
  messages: [
    {
      id: ASSETS_MESSAGE_ID,
      messageClass: ASSETS_CLASS,
      exceptionClass: 'RuntimeException',
      exceptionMessage: 'ESI timeout on assets page 3',
      failedAt: '2026-11-10T08:15:00+00:00',
      retryCount: 3,
    },
    {
      id: BLUEPRINTS_MESSAGE_ID,
      messageClass: BLUEPRINTS_CLASS,
      exceptionClass: 'LogicException',
      exceptionMessage: 'Blueprint page missing',
      failedAt: null,
      retryCount: null,
    },
  ],
}

const apiRequestMock = vi.mocked(apiRequest)

function respondToApi(
  listings: FailedMessageListing[],
  actionOutcome: () => Promise<unknown> = async () => ({ processed: 1, remaining: 0 }),
): void {
  let listingIndex = 0
  apiRequestMock.mockImplementation(async (path: string, options?: RequestInit) => {
    const isListing = path === FAILED_MESSAGES_PATH && (options?.method ?? 'GET') === 'GET'
    if (isListing) return listings[Math.min(listingIndex++, listings.length - 1)]
    return actionOutcome()
  })
}

function listingCalls(): number {
  return apiRequestMock.mock.calls.filter(([path, options]) => path === FAILED_MESSAGES_PATH && (options?.method ?? 'GET') === 'GET').length
}

function writeCalls(): Array<{ method: string; path: string; body: unknown }> {
  return apiRequestMock.mock.calls
    .filter(([, options]) => options?.method !== undefined && options.method !== 'GET')
    .map(([path, options]) => ({
      method: options?.method ?? '',
      path,
      body: typeof options?.body === 'string' ? JSON.parse(options.body) : undefined,
    }))
}

async function mountCard(locale: TestLocale = 'en'): Promise<VueWrapper> {
  const wrapper = mount(FailedMessagesCard, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

async function click(wrapper: VueWrapper, selector: string, index = 0): Promise<void> {
  await wrapper.findAll(selector)[index].trigger('click')
  await flushPromises()
}

async function confirm(wrapper: VueWrapper): Promise<void> {
  const confirmButton = wrapper.findAll('button').find(button => button.text() === en.common.actions.confirm)
  if (!confirmButton) throw new Error('Confirmation button not found')
  await confirmButton.trigger('click')
  await flushPromises()
}

describe('FailedMessagesCard', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    apiRequestMock.mockReset()
  })

  it.each(TEST_LOCALES)('writes the %s empty state when no message failed', async (locale) => {
    respondToApi([EMPTY_LISTING])

    const wrapper = await mountCard(locale)

    expect(wrapper.get('[data-testid="failed-messages-empty"]').text()).toBe(messageOf(locale, 'admin.failedMessages.empty'))
    expect(wrapper.find('[data-testid="failed-message-class"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="failed-message-row"]').exists()).toBe(false)
  })

  it('shows one block per message class with its count', async () => {
    respondToApi([FILLED_LISTING])

    const wrapper = await mountCard()

    const blocks = wrapper.findAll('[data-testid="failed-message-class"]')
    expect(blocks).toHaveLength(2)
    expect(blocks[0].text()).toContain('SyncCharacterAssets')
    expect(blocks[0].get('[data-testid="failed-message-class-count"]').text()).toBe('3')
    expect(blocks[1].text()).toContain('SyncBlueprints')
    expect(blocks[1].get('[data-testid="failed-message-class-count"]').text()).toBe('1')
    expect(wrapper.find('[data-testid="failed-messages-empty"]').exists()).toBe(false)
  })

  it('lists each failed message with its class, exception, message and retry count', async () => {
    respondToApi([FILLED_LISTING])

    const wrapper = await mountCard()

    const rows = wrapper.findAll('[data-testid="failed-message-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('SyncCharacterAssets')
    expect(rows[0].text()).toContain('RuntimeException')
    expect(rows[0].text()).toContain('ESI timeout on assets page 3')
    expect(rows[0].get('[data-testid="failed-message-retry-count"]').text()).toBe('3')
    expect(rows[1].text()).toContain('SyncBlueprints')
    expect(rows[1].text()).toContain('LogicException')
    expect(rows[1].text()).toContain('Blueprint page missing')
  })

  it('does not show a retry count of zero for a message whose retry count is unknown', async () => {
    respondToApi([FILLED_LISTING])

    const wrapper = await mountCard()

    const unknownRetryCount = wrapper.findAll('[data-testid="failed-message-row"]')[1].get('[data-testid="failed-message-retry-count"]')
    expect(unknownRetryCount.text()).not.toBe('0')
  })

  it('writes every label of the card in the interface language', async () => {
    respondToApi([FILLED_LISTING])

    for (const locale of TEST_LOCALES) {
      const wrapper = await mountCard(locale)
      expectOnlyLocaleTexts(wrapper.text(), locale, [
        'admin.failedMessages.title',
        'admin.failedMessages.retry',
        'admin.failedMessages.remove',
        'admin.failedMessages.retryClass',
        'admin.failedMessages.removeClass',
      ])
      wrapper.unmount()
    }
  })

  it('asks for a confirmation before removing a message and removes it only once confirmed', async () => {
    respondToApi([FILLED_LISTING, EMPTY_LISTING])
    const wrapper = await mountCard()

    await click(wrapper, '[data-testid="failed-message-remove"]', 1)

    expect(wrapper.text()).toContain(en.common.actions.confirm)
    expect(writeCalls()).toEqual([])

    await confirm(wrapper)

    expect(writeCalls()).toEqual([{ method: 'DELETE', path: `${FAILED_MESSAGES_PATH}/${BLUEPRINTS_MESSAGE_ID}`, body: undefined }])
    expect(listingCalls()).toBe(2)
  })

  it('removes nothing when the removal of a message is cancelled', async () => {
    respondToApi([FILLED_LISTING])
    const wrapper = await mountCard()

    await click(wrapper, '[data-testid="failed-message-remove"]')
    const cancelButton = wrapper.findAll('button').find(button => button.text() === en.common.actions.cancel)
    await cancelButton?.trigger('click')
    await flushPromises()

    expect(writeCalls()).toEqual([])
    expect(wrapper.text()).not.toContain(en.common.actions.confirm)
  })

  it('removes a whole class only after the confirmation and sends its message class', async () => {
    respondToApi([FILLED_LISTING, EMPTY_LISTING])
    const wrapper = await mountCard()

    await click(wrapper, '[data-testid="failed-message-class-remove"]', 1)

    expect(writeCalls()).toEqual([])

    await confirm(wrapper)

    expect(writeCalls()).toEqual([{ method: 'POST', path: `${FAILED_MESSAGES_PATH}/remove-class`, body: { messageClass: BLUEPRINTS_CLASS } }])
    expect(listingCalls()).toBe(2)
  })

  it('retries a message on a single click without any confirmation and reloads the list', async () => {
    respondToApi([FILLED_LISTING, EMPTY_LISTING])
    const wrapper = await mountCard()

    await click(wrapper, '[data-testid="failed-message-retry"]')

    expect(writeCalls()).toEqual([{ method: 'POST', path: `${FAILED_MESSAGES_PATH}/${ASSETS_MESSAGE_ID}/retry`, body: undefined }])
    expect(wrapper.text()).not.toContain(en.common.actions.confirm)
    expect(listingCalls()).toBe(2)
  })

  it('retries a whole class on a single click and sends its message class', async () => {
    respondToApi([FILLED_LISTING, EMPTY_LISTING])
    const wrapper = await mountCard()

    await click(wrapper, '[data-testid="failed-message-class-retry"]')

    expect(writeCalls()).toEqual([{ method: 'POST', path: `${FAILED_MESSAGES_PATH}/retry-class`, body: { messageClass: ASSETS_CLASS } }])
    expect(wrapper.text()).not.toContain(en.common.actions.confirm)
    expect(listingCalls()).toBe(2)
  })

  it('reloads the list when the message is already gone', async () => {
    respondToApi([FILLED_LISTING, EMPTY_LISTING], async () => {
      throw new ApiError('Failed message not found', 404)
    })
    const wrapper = await mountCard()

    await click(wrapper, '[data-testid="failed-message-retry"]')

    expect(listingCalls()).toBe(2)
    expect(wrapper.find('[data-testid="failed-message-row"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="failed-messages-empty"]').text()).toBe(messageOf('en', 'admin.failedMessages.empty'))
  })
})
