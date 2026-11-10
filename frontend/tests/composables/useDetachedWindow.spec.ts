import { useDetachedWindow } from '@/composables/useDetachedWindow'

type PictureInPictureWindow = Window & typeof globalThis

function detachedWindowStub(): PictureInPictureWindow {
  return {
    document: document.implementation.createHTMLDocument('companion'),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    resizeTo: vi.fn(),
    close: vi.fn(),
    closed: false,
  } as unknown as PictureInPictureWindow
}

const BAR_SIZE = { width: 440, height: 72 }

beforeEach(() => {
  Reflect.deleteProperty(window, 'documentPictureInPicture')
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('useDetachedWindow', () => {
  it('opens a popup of the requested size when Document Picture-in-Picture does not exist', async () => {
    const popup = detachedWindowStub()
    const windowOpen = vi.spyOn(window, 'open').mockReturnValue(popup)
    const detached = useDetachedWindow()

    const opened = await detached.open(BAR_SIZE)

    const features = String(windowOpen.mock.calls[0]?.[2])
    expect({
      kind: opened?.kind,
      sameWindow: opened?.window === popup,
      features: ['popup=yes', 'width=440', 'height=72'].map(feature => features.includes(feature)),
      isOpen: detached.isOpen.value,
    }).toEqual({ kind: 'popup', sameWindow: true, features: [true, true, true], isOpen: true })
  })

  it('returns null and stays closed when the browser refuses the popup', async () => {
    vi.spyOn(window, 'open').mockReturnValue(null)
    const detached = useDetachedWindow()

    const opened = await detached.open(BAR_SIZE)

    expect({ opened, isOpen: detached.isOpen.value }).toEqual({ opened: null, isOpen: false })
  })
})
