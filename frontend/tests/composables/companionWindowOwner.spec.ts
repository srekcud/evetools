import { createPinia, setActivePinia } from 'pinia'
import { releaseCompanionWindow } from '@/composables/companionWindowOwner'
import { useDetachedWindow } from '@/composables/useDetachedWindow'
import { useToast } from '@/composables/useToast'
import { i18n } from '@/i18n'
import { useMiningCompanionStore } from '@/stores/miningCompanion'
import { openCompanionWindow } from '@/views/MiningSite/miningCompanion'

vi.mock(import('@/views/MiningSite/miningCompanion'), async importOriginal => ({
  ...(await importOriginal()),
  openCompanionWindow: vi.fn(),
}))

const BAR_SIZE = { width: 440, height: 72 }
const SITE_TARGET = { siteId: '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f', operationId: null }
const MINING_CLOSED_KEY = 'companionWindow.replaced.mining'
const GROUP_CLOSED_KEY = 'companionWindow.replaced.group'

interface FakeWindow {
  listeners: Map<string, () => void>
  document: Document
  addEventListener: (type: string, listener: () => void) => void
  removeEventListener: (type: string) => void
  focus: ReturnType<typeof vi.fn>
  resizeTo: ReturnType<typeof vi.fn>
  close: ReturnType<typeof vi.fn>
}

function fakeWindow(): FakeWindow {
  const listeners = new Map<string, () => void>()
  return {
    listeners,
    document: document.implementation.createHTMLDocument('companion'),
    addEventListener: (type, listener) => { listeners.set(type, listener) },
    removeEventListener: (type) => { listeners.delete(type) },
    focus: vi.fn(),
    resizeTo: vi.fn(),
    close: vi.fn(),
  }
}

function groupGrantsPictureInPicture(granted: FakeWindow): void {
  Object.defineProperty(window, 'documentPictureInPicture', {
    value: { requestWindow: vi.fn().mockResolvedValue(granted) },
    configurable: true,
  })
}

function miningGrants(kind: 'pip' | 'popup', granted: FakeWindow): void {
  vi.mocked(openCompanionWindow).mockResolvedValue({ kind, window: granted as unknown as Window, notice: null, cause: null })
}

function toastMessages(): string[] {
  return useToast().toasts.value.map(toast => toast.message)
}

function closeByUser(closing: FakeWindow): void {
  closing.listeners.get('pagehide')?.()
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  useToast().toasts.value = []
  releaseCompanionWindow('mining')
  releaseCompanionWindow('group')
  Reflect.deleteProperty(window, 'documentPictureInPicture')
  vi.mocked(openCompanionWindow).mockReset()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('single companion window per tab', () => {
  it('replaces the mining companion with the group companion and tells which one was closed', async () => {
    const mining = useMiningCompanionStore()
    miningGrants('pip', fakeWindow())
    await mining.detach(SITE_TARGET)
    const group = useDetachedWindow('group')
    groupGrantsPictureInPicture(fakeWindow())

    await group.open(BAR_SIZE)

    expect({ miningWindow: mining.companionWindow, miningTarget: mining.target, groupOpen: group.isOpen.value, toasts: toastMessages() })
      .toEqual({ miningWindow: null, miningTarget: null, groupOpen: true, toasts: [i18n.global.t(MINING_CLOSED_KEY)] })
    expect(i18n.global.t(MINING_CLOSED_KEY)).not.toBe(MINING_CLOSED_KEY)
  })

  it('replaces the group companion with the mining companion and tells which one was closed', async () => {
    const group = useDetachedWindow('group')
    groupGrantsPictureInPicture(fakeWindow())
    await group.open(BAR_SIZE)
    const mining = useMiningCompanionStore()
    miningGrants('pip', fakeWindow())

    await mining.detach(SITE_TARGET)

    expect({ groupOpen: group.isOpen.value, groupWindow: group.currentWindow.value, miningKind: mining.windowKind, toasts: toastMessages() })
      .toEqual({ groupOpen: false, groupWindow: null, miningKind: 'pip', toasts: [i18n.global.t(GROUP_CLOSED_KEY)] })
    expect(i18n.global.t(GROUP_CLOSED_KEY)).not.toBe(GROUP_CLOSED_KEY)
  })

  it('shows no toast when the user closed the other companion before opening this one', async () => {
    const mining = useMiningCompanionStore()
    const miningWindow = fakeWindow()
    miningGrants('pip', miningWindow)
    await mining.detach(SITE_TARGET)
    closeByUser(miningWindow)
    const group = useDetachedWindow('group')
    groupGrantsPictureInPicture(fakeWindow())

    await group.open(BAR_SIZE)

    expect({ groupOpen: group.isOpen.value, toasts: toastMessages() }).toEqual({ groupOpen: true, toasts: [] })
  })

  it('shows no toast when the user closes the companion with the close button', async () => {
    const mining = useMiningCompanionStore()
    miningGrants('pip', fakeWindow())
    await mining.detach(SITE_TARGET)

    mining.close()

    expect({ miningWindow: mining.companionWindow, toasts: toastMessages() }).toEqual({ miningWindow: null, toasts: [] })
  })

  it('shows no toast when the same companion opens again after the user closed it', async () => {
    const group = useDetachedWindow('group')
    const firstWindow = fakeWindow()
    groupGrantsPictureInPicture(firstWindow)
    await group.open(BAR_SIZE)
    closeByUser(firstWindow)
    groupGrantsPictureInPicture(fakeWindow())

    await group.open(BAR_SIZE)

    expect({ groupOpen: group.isOpen.value, toasts: toastMessages() }).toEqual({ groupOpen: true, toasts: [] })
  })

  it('keeps the other companion open and shows no toast when the new window is a popup', async () => {
    const group = useDetachedWindow('group')
    groupGrantsPictureInPicture(fakeWindow())
    await group.open(BAR_SIZE)
    const mining = useMiningCompanionStore()
    miningGrants('popup', fakeWindow())

    await mining.detach(SITE_TARGET)

    expect({ groupOpen: group.isOpen.value, miningKind: mining.windowKind, toasts: toastMessages() })
      .toEqual({ groupOpen: true, miningKind: 'popup', toasts: [] })
  })
})
