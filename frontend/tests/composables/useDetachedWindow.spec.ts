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

  it('requests a single Picture-in-Picture window when open is called again while the first request is pending', async () => {
    const pictureInPictureWindow = detachedWindowStub()
    let grant: (granted: Window) => void = () => {}
    const requestWindow = vi.fn(() => new Promise<Window>((resolve) => { grant = resolve }))
    Object.defineProperty(window, 'documentPictureInPicture', { value: { requestWindow }, configurable: true })
    const detached = useDetachedWindow()

    const first = detached.open(BAR_SIZE)
    const second = detached.open(BAR_SIZE)
    expect(detached.isOpening.value).toBe(true)
    grant(pictureInPictureWindow)
    const [firstHandle, secondHandle] = await Promise.all([first, second])

    expect({
      requests: requestWindow.mock.calls.length,
      sameHandle: firstHandle === secondHandle,
      shownWindow: detached.currentWindow.value === pictureInPictureWindow,
      isOpen: detached.isOpen.value,
      isOpening: detached.isOpening.value,
    }).toEqual({ requests: 1, sameHandle: true, shownWindow: true, isOpen: true, isOpening: false })
  })

  it('opens a new window after the previous one was closed', async () => {
    const windows = [detachedWindowStub(), detachedWindowStub()]
    const requestWindow = vi.fn(async () => windows[requestWindow.mock.calls.length - 1] as Window)
    Object.defineProperty(window, 'documentPictureInPicture', { value: { requestWindow }, configurable: true })
    const detached = useDetachedWindow()

    await detached.open(BAR_SIZE)
    detached.close()
    await detached.open(BAR_SIZE)

    expect({
      requests: requestWindow.mock.calls.length,
      shownWindow: detached.currentWindow.value === windows[1],
      isOpen: detached.isOpen.value,
    }).toEqual({ requests: 2, shownWindow: true, isOpen: true })
  })

  it('returns null and stays closed when the browser refuses the popup', async () => {
    vi.spyOn(window, 'open').mockReturnValue(null)
    const detached = useDetachedWindow()

    const opened = await detached.open(BAR_SIZE)

    expect({ opened, isOpen: detached.isOpen.value }).toEqual({ opened: null, isOpen: false })
  })
})
