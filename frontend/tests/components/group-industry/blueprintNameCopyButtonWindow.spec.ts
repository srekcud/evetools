import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import BlueprintNameCopyButton from '@/components/group-industry/BlueprintNameCopyButton.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const BLUEPRINT_NAME = 'Ferox Blueprint'
const MAIN_WINDOW_REFUSAL = new DOMException('Document is not focused.', 'NotAllowedError')

const mainWindowWriteText = vi.fn<(text: string) => Promise<void>>()
const detachedWindowWriteText = vi.fn<(text: string) => Promise<void>>()

let detachedFrame: HTMLIFrameElement

function detachedWindow(): Window {
  const detachedWindowHandle = detachedFrame.contentWindow
  if (detachedWindowHandle === null) throw new Error('The detached window stand-in has no window')
  return detachedWindowHandle
}

function mountIn(host: HTMLElement): VueWrapper {
  return mount(BlueprintNameCopyButton, {
    props: { blueprintName: BLUEPRINT_NAME },
    attachTo: host,
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  mainWindowWriteText.mockReset()
  mainWindowWriteText.mockRejectedValue(MAIN_WINDOW_REFUSAL)
  detachedWindowWriteText.mockReset()
  detachedWindowWriteText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText: mainWindowWriteText }, configurable: true })
  detachedFrame = document.createElement('iframe')
  document.body.appendChild(detachedFrame)
  Object.defineProperty(detachedWindow().navigator, 'clipboard', { value: { writeText: detachedWindowWriteText }, configurable: true })
})

afterEach(() => {
  detachedFrame.remove()
})

describe('BlueprintNameCopyButton clipboard window', () => {
  it('copies through the clipboard of the detached window it is rendered in, not the main window', async () => {
    const wrapper = mountIn(detachedWindow().document.body)
    const button = wrapper.get('[data-testid="planning-copy-blueprint-name"]')

    await button.trigger('click')
    await flushPromises()

    expect({
      copiedInDetachedWindow: detachedWindowWriteText.mock.calls,
      askedMainWindow: mainWindowWriteText.mock.calls,
      state: button.attributes('data-state'),
    }).toEqual({ copiedInDetachedWindow: [[BLUEPRINT_NAME]], askedMainWindow: [], state: 'copied' })
  })

  it('still copies through the main window clipboard when rendered in the main window', async () => {
    mainWindowWriteText.mockResolvedValue(undefined)
    const wrapper = mountIn(document.body)
    const button = wrapper.get('[data-testid="planning-copy-blueprint-name"]')

    await button.trigger('click')
    await flushPromises()

    expect({
      copiedInMainWindow: mainWindowWriteText.mock.calls,
      askedDetachedWindow: detachedWindowWriteText.mock.calls,
      state: button.attributes('data-state'),
    }).toEqual({ copiedInMainWindow: [[BLUEPRINT_NAME]], askedDetachedWindow: [], state: 'copied' })
  })

  it('reports the refusal of the detached window clipboard on the button', async () => {
    detachedWindowWriteText.mockRejectedValue(new DOMException('denied', 'NotAllowedError'))
    const wrapper = mountIn(detachedWindow().document.body)
    const button = wrapper.get('[data-testid="planning-copy-blueprint-name"]')

    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('data-state')).toBe('failed')
  })
})
