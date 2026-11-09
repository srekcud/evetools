import {
  COMPANION_SIZES,
  CompanionWindowError,
  DEFAULT_COMPANION_SIZE,
  companionShortcutOf,
  openCompanionWindow,
  readCompanionSize,
} from '@/views/MiningSite/miningCompanion'

type ShortcutEvent = Parameters<typeof companionShortcutOf>[0]

function keyEvent(key: string, overrides: Partial<ShortcutEvent> = {}): ShortcutEvent {
  return { key, ctrlKey: false, metaKey: false, altKey: false, target: null, ...overrides }
}

function editableDiv(): HTMLElement {
  const element = document.createElement('div')
  element.setAttribute('contenteditable', 'true')
  return element
}

function storageWith(value: string | null): Pick<Storage, 'getItem'> {
  return { getItem: vi.fn((key: string) => (key === 'miningCompanion.size' ? value : null)) }
}

function windowStub(label: string): Window {
  return { label } as unknown as Window
}

function hostOf(parts: { requestWindow?: ReturnType<typeof vi.fn>; open: ReturnType<typeof vi.fn> }): Window {
  const documentPictureInPicture = parts.requestWindow === undefined ? undefined : { requestWindow: parts.requestWindow }
  return { documentPictureInPicture, open: parts.open } as unknown as Window
}

describe('companion sizes and shortcuts', () => {
  it('names the three sizes with their dimensions, defaults to compact and reads the stored size tolerantly', () => {
    expect({
      sizes: Object.fromEntries(Object.entries(COMPANION_SIZES).map(([size, { width, height }]) => [size, { width, height }])),
      defaultSize: DEFAULT_COMPANION_SIZE,
      stored: [storageWith('bar'), storageWith('detail'), storageWith('huge'), storageWith(null)].map(readCompanionSize),
    }).toEqual({
      sizes: {
        bar: { width: 320, height: 110 },
        compact: { width: 340, height: 520 },
        detail: { width: 380, height: 1000 },
      },
      defaultSize: 'compact',
      stored: ['bar', 'detail', 'compact', 'compact'],
    })
  })

  it('maps B, C, L to a size, V to paste and D to detach whatever the case, and ignores modifiers, text fields and unknown keys', () => {
    expect({
      lowerB: companionShortcutOf(keyEvent('b')),
      upperB: companionShortcutOf(keyEvent('B')),
      lowerC: companionShortcutOf(keyEvent('c')),
      upperL: companionShortcutOf(keyEvent('L')),
      lowerV: companionShortcutOf(keyEvent('v')),
      lowerD: companionShortcutOf(keyEvent('d')),
      withCtrl: companionShortcutOf(keyEvent('b', { ctrlKey: true })),
      withMeta: companionShortcutOf(keyEvent('v', { metaKey: true })),
      withAlt: companionShortcutOf(keyEvent('d', { altKey: true })),
      inInput: companionShortcutOf(keyEvent('b', { target: document.createElement('input') })),
      inTextarea: companionShortcutOf(keyEvent('c', { target: document.createElement('textarea') })),
      inSelect: companionShortcutOf(keyEvent('l', { target: document.createElement('select') })),
      inEditable: companionShortcutOf(keyEvent('v', { target: editableDiv() })),
      unknownKey: companionShortcutOf(keyEvent('x')),
    }).toEqual({
      lowerB: 'size:bar',
      upperB: 'size:bar',
      lowerC: 'size:compact',
      upperL: 'size:detail',
      lowerV: 'paste',
      lowerD: 'detach',
      withCtrl: null,
      withMeta: null,
      withAlt: null,
      inInput: null,
      inTextarea: null,
      inSelect: null,
      inEditable: null,
      unknownKey: null,
    })
  })
})

describe('openCompanionWindow', () => {
  it('opens a Picture-in-Picture window of the requested size when the browser offers it', async () => {
    const pipWindow = windowStub('pip')
    const requestWindow = vi.fn().mockResolvedValue(pipWindow)
    const open = vi.fn()

    const opened = await openCompanionWindow(hostOf({ requestWindow, open }), 'compact')

    expect({ opened, requested: requestWindow.mock.calls, popupsOpened: open.mock.calls.length }).toEqual({
      opened: { kind: 'pip', window: pipWindow, notice: null, cause: null },
      requested: [[{ width: 340, height: 520 }]],
      popupsOpened: 0,
    })
  })

  it('falls back to a popup with the fallback notice when Picture-in-Picture is missing, and joins the cause when it is refused', async () => {
    const popupWindow = windowStub('popup')
    const openPopup = vi.fn().mockReturnValue(popupWindow)
    const refusedOpen = vi.fn().mockReturnValue(popupWindow)
    const refusal = vi.fn().mockRejectedValue(new Error('Not allowed without a user gesture'))

    const withoutPip = await openCompanionWindow(hostOf({ open: openPopup }), 'detail')
    const refusedPip = await openCompanionWindow(hostOf({ requestWindow: refusal, open: refusedOpen }), 'bar')

    expect({ withoutPip, popupCall: openPopup.mock.calls, refusedPip, refusedPopupCall: refusedOpen.mock.calls }).toEqual({
      withoutPip: { kind: 'popup', window: popupWindow, notice: 'popupFallback', cause: null },
      popupCall: [['', 'evetools-compagnon', 'popup,width=380,height=1000']],
      refusedPip: { kind: 'popup', window: popupWindow, notice: 'popupFallback', cause: 'Not allowed without a user gesture' },
      refusedPopupCall: [['', 'evetools-compagnon', 'popup,width=320,height=110']],
    })
  })

  it('rejects with a popup_blocked error and opens nothing when the popup is blocked after a refused Picture-in-Picture', async () => {
    const requestWindow = vi.fn().mockRejectedValue(new Error('Not allowed'))
    const open = vi.fn().mockReturnValue(null)

    const failure = await openCompanionWindow(hostOf({ requestWindow, open }), 'compact').then(() => null, (error: unknown) => error)

    expect(failure).toBeInstanceOf(CompanionWindowError)
    expect(failure).toMatchObject({ code: 'popup_blocked' })
    expect(open).toHaveBeenCalledTimes(1)
  })
})
