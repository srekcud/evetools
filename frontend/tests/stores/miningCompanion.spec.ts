import { createPinia, setActivePinia } from 'pinia'
import { COMPANION_SIZES, CompanionWindowError, openCompanionWindow } from '@/views/MiningSite/miningCompanion'
import { useMiningCompanionStore } from '@/stores/miningCompanion'

vi.mock(import('@/views/MiningSite/miningCompanion'), async importOriginal => ({
  ...(await importOriginal()),
  openCompanionWindow: vi.fn(),
}))

const SITE_TARGET = { siteId: '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f', operationId: null }

interface FakeWindow {
  focus: ReturnType<typeof vi.fn>
  resizeTo: ReturnType<typeof vi.fn>
  listeners: Map<string, () => void>
  addEventListener: (type: string, listener: () => void) => void
  removeEventListener: ReturnType<typeof vi.fn>
  close: ReturnType<typeof vi.fn>
}

function fakeWindow(): FakeWindow {
  const listeners = new Map<string, () => void>()
  return {
    focus: vi.fn(),
    resizeTo: vi.fn(),
    listeners,
    addEventListener: (type, listener) => { listeners.set(type, listener) },
    removeEventListener: vi.fn(),
    close: vi.fn(),
  }
}

function opensAs(kind: 'pip' | 'popup', opened: FakeWindow): void {
  vi.mocked(openCompanionWindow).mockResolvedValue({ kind, window: opened as unknown as Window, notice: null, cause: null })
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(openCompanionWindow).mockReset()
})

describe('miningCompanion store', () => {
  it('opens one window, brings it to the front on a second detach and goes back to closed when the window is hidden', async () => {
    const opened = fakeWindow()
    opensAs('pip', opened)
    const store = useMiningCompanionStore()

    await store.detach(SITE_TARGET)
    const afterFirstDetach = { kind: store.windowKind, target: store.target, size: store.size, focused: opened.focus.mock.calls.length }
    await store.detach(SITE_TARGET)
    const afterSecondDetach = { opens: vi.mocked(openCompanionWindow).mock.calls.length, focused: opened.focus.mock.calls.length }
    opened.listeners.get('pagehide')?.()

    expect({ afterFirstDetach, afterSecondDetach, kindAfterPagehide: store.windowKind }).toEqual({
      afterFirstDetach: { kind: 'pip', target: SITE_TARGET, size: 'compact', focused: 0 },
      afterSecondDetach: { opens: 1, focused: 1 },
      kindAfterPagehide: null,
    })
    expect(vi.mocked(openCompanionWindow)).toHaveBeenCalledWith(window, 'compact')
  })

  it('shows the popup blocked notice and stays closed when no window can be opened', async () => {
    vi.mocked(openCompanionWindow).mockRejectedValue(new CompanionWindowError('popup_blocked'))
    const store = useMiningCompanionStore()

    await store.detach(SITE_TARGET)

    expect({ kind: store.windowKind, notice: store.notice }).toEqual({ kind: null, notice: 'miningCompanion.notices.popupBlocked' })
  })

  it('resizes the open window to the size, and keeps the new size with the resize refused notice when the browser throws', async () => {
    const opened = fakeWindow()
    opensAs('popup', opened)
    const store = useMiningCompanionStore()
    await store.detach(SITE_TARGET)

    store.setSize('bar')
    const afterBar = { size: store.size, resized: [...opened.resizeTo.mock.calls], notice: store.notice }
    opened.resizeTo.mockImplementation(() => { throw new Error('resizeTo blocked') })
    store.setSize('detail')

    expect({ afterBar, afterDetail: { size: store.size, resized: opened.resizeTo.mock.calls[opened.resizeTo.mock.calls.length - 1], notice: store.notice } }).toEqual({
      afterBar: { size: 'bar', resized: [[COMPANION_SIZES.bar.width, COMPANION_SIZES.bar.height]], notice: null },
      afterDetail: { size: 'detail', resized: [COMPANION_SIZES.detail.width, COMPANION_SIZES.detail.height], notice: 'miningCompanion.notices.resizeRefused' },
    })
  })
})
