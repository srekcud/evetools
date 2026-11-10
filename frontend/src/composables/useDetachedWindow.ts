import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'

export type DetachedWindowKind = 'pip' | 'popup'

export type DetachedWindowNotice = 'popupBlocked' | 'popupFallback' | 'resizeRefused'

export type DetachedWindowSize = { width: number; height: number }

export type DetachedWindowHandle = {
  window: Window
  kind: DetachedWindowKind
  notice: 'popupFallback' | null
  cause: string | null
}

type DocumentPictureInPicture = {
  requestWindow: (options: DetachedWindowSize) => Promise<Window>
}

export const PROJECT_COMPANION_WINDOW_NAME = 'evetools-compagnon-projet'

const WINDOW_HIDDEN_EVENT = 'pagehide'

function documentPictureInPictureOf(source: Window): DocumentPictureInPicture | undefined {
  return (source as Window & { documentPictureInPicture?: DocumentPictureInPicture }).documentPictureInPicture
}

function popupFeatures(size: DetachedWindowSize): string {
  return `popup=yes,width=${size.width},height=${size.height}`
}

function causeText(failure: unknown): string {
  return failure instanceof Error ? failure.message : String(failure)
}

function copyStyleSheets(target: Window): void {
  for (const node of document.querySelectorAll<HTMLLinkElement | HTMLStyleElement>('link[rel="stylesheet"], style')) {
    if (node instanceof HTMLLinkElement) {
      const link = target.document.createElement('link')
      link.rel = 'stylesheet'
      link.href = node.href
      target.document.head.appendChild(link)
    } else {
      target.document.head.appendChild(target.document.importNode(node, true))
    }
  }
}

export function useDetachedWindow(): {
  isOpen: Ref<boolean>
  kind: Ref<DetachedWindowKind | null>
  currentWindow: ShallowRef<Window | null>
  notice: Ref<DetachedWindowNotice | null>
  noticeCause: Ref<string | null>
  open: (size: DetachedWindowSize) => Promise<DetachedWindowHandle | null>
  close: () => void
  resize: (width: number, height: number) => void
} {
  const isOpen = ref(false)
  const kind = ref<DetachedWindowKind | null>(null)
  const currentWindow = shallowRef<Window | null>(null)
  const notice = ref<DetachedWindowNotice | null>(null)
  const noticeCause = ref<string | null>(null)

  function markClosed(): void {
    isOpen.value = false
    kind.value = null
    currentWindow.value = null
  }

  function close(): void {
    const closing = currentWindow.value
    if (closing === null) return
    closing.removeEventListener(WINDOW_HIDDEN_EVENT, markClosed)
    markClosed()
    closing.close()
  }

  async function requestWindow(size: DetachedWindowSize): Promise<DetachedWindowHandle | null> {
    const pictureInPicture = documentPictureInPictureOf(window)
    let cause: string | null = null
    if (pictureInPicture !== undefined) {
      try {
        return { window: await pictureInPicture.requestWindow(size), kind: 'pip', notice: null, cause: null }
      } catch (refusal) {
        cause = causeText(refusal)
      }
    }
    const popup = window.open('', PROJECT_COMPANION_WINDOW_NAME, popupFeatures(size))
    if (popup === null) {
      notice.value = 'popupBlocked'
      noticeCause.value = cause
      return null
    }
    return { window: popup, kind: 'popup', notice: 'popupFallback', cause }
  }

  async function open(size: DetachedWindowSize): Promise<DetachedWindowHandle | null> {
    close()
    notice.value = null
    noticeCause.value = null
    const handle = await requestWindow(size)
    if (handle === null) return null
    copyStyleSheets(handle.window)
    handle.window.addEventListener(WINDOW_HIDDEN_EVENT, markClosed)
    currentWindow.value = handle.window
    isOpen.value = true
    kind.value = handle.kind
    notice.value = handle.notice
    noticeCause.value = handle.cause
    return handle
  }

  function resize(width: number, height: number): void {
    const resizing = currentWindow.value
    if (resizing === null) return
    try {
      resizing.resizeTo(width, height)
      if (notice.value === 'resizeRefused') notice.value = null
    } catch {
      notice.value = 'resizeRefused'
      noticeCause.value = null
    }
  }

  return { isOpen, kind, currentWindow, notice, noticeCause, open, close, resize }
}
