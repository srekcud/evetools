import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import MiningOperationShareRecap from '@/components/mining-operation/MiningOperationShareRecap.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  PARTICIPANT_NAME_PARTS,
  PROPORTIONAL_RECAP_LINES,
  minedLedger,
  recapOperation,
  recapValuation,
} from '../../views/MiningOperation/miningOperationShareRecapFixtures'

const TEXT = '[data-test="recap-text"]'
const PREVIEW = '[data-test="recap-preview"]'
const COPY_TEXT = '[data-test="copy-recap-text"]'
const COPY_IMAGE = '[data-test="copy-recap-image"]'

class FakeClipboardItem {
  constructor(readonly items: Record<string, Blob>) {}
}

const clipboard = { writeText: vi.fn(), write: vi.fn() }

const drawingContext: Record<PropertyKey, unknown> = new Proxy({ measureText: (text: string) => ({ width: text.length * 8 }) } as Record<PropertyKey, unknown>, {
  get: (target, name) => (name in target ? target[name] : vi.fn(() => drawingContext)),
  set: (target, name, value) => {
    target[name] = value
    return true
  },
})

function stubCanvas(context: unknown = drawingContext) {
  const getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as never)
  const toBlob = vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(callback => callback(new Blob(['png'], { type: 'image/png' })))
  return { getContext, toBlob }
}

function mountRecap(locale: TestLocale = 'en') {
  return mount(MiningOperationShareRecap, {
    props: { operation: recapOperation(), valuation: recapValuation(), ledger: minedLedger(), marketLabel: 'Jita' },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

beforeEach(() => {
  clipboard.writeText = vi.fn().mockResolvedValue(undefined)
  clipboard.write = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true })
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  Reflect.deleteProperty(navigator, 'clipboard')
})

enableAutoUnmount(afterEach)

describe('MiningOperationShareRecap', () => {
  it('copies the exact text to the clipboard and shows the button as copied', async () => {
    stubCanvas()
    vi.stubGlobal('ClipboardItem', FakeClipboardItem)
    const wrapper = mountRecap()

    await wrapper.get(COPY_TEXT).trigger('click')
    await flushPromises()

    expect(clipboard.writeText.mock.calls).toEqual([[PROPORTIONAL_RECAP_LINES.join('\n')]])
    expect({ shownText: wrapper.get(TEXT).element.textContent, label: wrapper.get(COPY_TEXT).text() }).toEqual({
      shownText: PROPORTIONAL_RECAP_LINES.join('\n'),
      label: messageOf('en', 'miningOperation.shareRecap.copied'),
    })
  })

  it('copies the preview as a PNG image to the clipboard and shows the button as copied', async () => {
    const { toBlob } = stubCanvas()
    vi.stubGlobal('ClipboardItem', FakeClipboardItem)
    const wrapper = mountRecap()

    await wrapper.get(COPY_IMAGE).trigger('click')
    await flushPromises()

    expect(toBlob).toHaveBeenCalledExactlyOnceWith(expect.any(Function), 'image/png')
    expect(clipboard.write).toHaveBeenCalledOnce()
    const [clipboardItems] = clipboard.write.mock.calls[0] as [FakeClipboardItem[]]
    expect(clipboardItems).toHaveLength(1)
    expect(clipboardItems.every(item => item instanceof FakeClipboardItem)).toBe(true)
    expect(clipboardItems.map(item => Object.entries(item.items).map(([mimeType, blob]) => [mimeType, blob.type]))).toEqual([[['image/png', 'image/png']]])
    expect(wrapper.get(COPY_IMAGE).text()).toBe(messageOf('en', 'miningOperation.shareRecap.copied'))
  })

  it('disables the image button with a tooltip when ClipboardItem is missing or the canvas cannot be drawn', () => {
    stubCanvas()
    vi.stubGlobal('ClipboardItem', undefined)
    const withoutClipboardItem = mountRecap()

    vi.stubGlobal('ClipboardItem', FakeClipboardItem)
    stubCanvas(null)
    const withoutCanvas = mountRecap()

    const tooltip = messageOf('en', 'miningOperation.shareRecap.imageUnavailable')
    expect([withoutClipboardItem, withoutCanvas].map(wrapper => ({ disabled: wrapper.get(COPY_IMAGE).attributes('disabled') !== undefined, title: wrapper.get(COPY_IMAGE).attributes('title') }))).toEqual([
      { disabled: true, title: tooltip },
      { disabled: true, title: tooltip },
    ])
  })

  it('previews the image with the figures and the reference but no participant name, in each locale', () => {
    stubCanvas()
    vi.stubGlobal('ClipboardItem', FakeClipboardItem)

    for (const locale of TEST_LOCALES) {
      const wrapper = mountRecap(locale)

      const preview = wrapper.get(PREVIEW).text()

      for (const expected of ['Anomalie Spodumain — 20:00', 'OP-2B7A53', '2.05B', '512.75M', 'Spodumain', '1.20B']) expect(preview, `${expected} in ${locale}`).toContain(expected)
      for (const name of PARTICIPANT_NAME_PARTS) expect(wrapper.text().includes(name), `${name} in ${locale}`).toBe(false)
      expectOnlyLocaleTexts(wrapper.text(), locale, ['miningOperation.shareRecap.title', 'miningOperation.shareRecap.copyText', 'miningOperation.shareRecap.copyImage', 'miningOperation.shareRecap.imageHint'])
    }
  })
})
