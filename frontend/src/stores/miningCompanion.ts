import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import { claimCompanionWindow, otherOwnerHolding, releaseCompanionWindow } from '@/composables/companionWindowOwner'
import {
  COMPANION_CLIPBOARD_STORAGE_KEY,
  COMPANION_NOTIFICATIONS_STORAGE_KEY,
  COMPANION_OPACITY_DEFAULT,
  COMPANION_OPACITY_STORAGE_KEY,
  COMPANION_SIZES,
  COMPANION_SIZE_STORAGE_KEY,
  COMPANION_TEXT_SCALE_STORAGE_KEY,
  CompanionWindowError,
  DEFAULT_COMPANION_SIZE,
  DEFAULT_COMPANION_TEXT_SCALE,
  clampCompanionOpacity,
  companionFlagValue,
  openCompanionWindow,
  readCompanionClipboard,
  readCompanionNotifications,
  readCompanionOpacity,
  readCompanionSize,
  readCompanionTextScale,
  type CompanionSizeKey,
  type CompanionTextScaleKey,
} from '@/views/MiningSite/miningCompanion'

export interface CompanionTarget {
  siteId: string | null
  operationId: string | null
}

export interface CompanionSettings {
  opacity: number
  textScale: CompanionTextScaleKey
  notifications: boolean
  clipboardOnFocus: boolean
}

const POPUP_BLOCKED_NOTICE = 'miningCompanion.notices.popupBlocked'
const POPUP_FALLBACK_NOTICE = 'miningCompanion.notices.popupFallback'
const RESIZE_REFUSED_NOTICE = 'miningCompanion.notices.resizeRefused'
const WINDOW_CLOSED_EVENT = 'pagehide'

function remember(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    return
  }
}

function recall<Value>(read: (storage: Pick<Storage, 'getItem'>) => Value, fallback: Value): Value {
  try {
    return read(localStorage)
  } catch {
    return fallback
  }
}

export const useMiningCompanionStore = defineStore('miningCompanion', () => {
  const target = ref<CompanionTarget | null>(null)
  const size = ref<CompanionSizeKey>(recall(readCompanionSize, DEFAULT_COMPANION_SIZE))
  const windowKind = ref<'pip' | 'popup' | null>(null)
  const notice = ref<string | null>(null)
  const noticeCause = ref<string | null>(null)
  const companionWindow = shallowRef<Window | null>(null)
  const settings = ref<CompanionSettings>({
    opacity: recall(readCompanionOpacity, COMPANION_OPACITY_DEFAULT),
    textScale: recall(readCompanionTextScale, DEFAULT_COMPANION_TEXT_SCALE),
    notifications: recall(readCompanionNotifications, false),
    clipboardOnFocus: recall(readCompanionClipboard, false),
  })
  let opening = false

  function reset(): void {
    releaseCompanionWindow('mining')
    companionWindow.value = null
    windowKind.value = null
    target.value = null
  }

  function watchClosing(opened: Window): void {
    const onClosed = (): void => {
      opened.removeEventListener(WINDOW_CLOSED_EVENT, onClosed)
      if (companionWindow.value === opened) reset()
    }
    opened.addEventListener(WINDOW_CLOSED_EVENT, onClosed)
  }

  async function detach(requested: CompanionTarget): Promise<void> {
    if (companionWindow.value !== null) {
      target.value = requested
      companionWindow.value.focus()
      return
    }
    if (opening) return
    opening = true
    notice.value = null
    noticeCause.value = null
    try {
      const heldByOther = otherOwnerHolding('mining')
      const opened = await openCompanionWindow(window, size.value)
      companionWindow.value = opened.window
      windowKind.value = opened.kind
      target.value = requested
      notice.value = opened.notice === 'popupFallback' ? POPUP_FALLBACK_NOTICE : null
      noticeCause.value = opened.cause
      watchClosing(opened.window)
      claimCompanionWindow('mining', opened.kind, heldByOther, close)
    } catch (failure) {
      if (!(failure instanceof CompanionWindowError)) throw failure
      notice.value = POPUP_BLOCKED_NOTICE
    } finally {
      opening = false
    }
  }

  function close(): void {
    const opened = companionWindow.value
    reset()
    opened?.close()
  }

  function setSize(requested: CompanionSizeKey): void {
    size.value = requested
    remember(COMPANION_SIZE_STORAGE_KEY, requested)
    const opened = companionWindow.value
    if (opened === null) return
    try {
      opened.resizeTo(COMPANION_SIZES[requested].width, COMPANION_SIZES[requested].height)
      if (notice.value === RESIZE_REFUSED_NOTICE) notice.value = null
    } catch {
      notice.value = RESIZE_REFUSED_NOTICE
      noticeCause.value = null
    }
  }

  function setOpacity(requested: number): void {
    settings.value = { ...settings.value, opacity: clampCompanionOpacity(requested) }
    remember(COMPANION_OPACITY_STORAGE_KEY, String(settings.value.opacity))
  }

  function setTextScale(requested: CompanionTextScaleKey): void {
    settings.value = { ...settings.value, textScale: requested }
    remember(COMPANION_TEXT_SCALE_STORAGE_KEY, requested)
  }

  function retarget(requested: CompanionTarget): void {
    target.value = requested
  }

  function setNotifications(enabled: boolean): void {
    settings.value = { ...settings.value, notifications: enabled }
    remember(COMPANION_NOTIFICATIONS_STORAGE_KEY, companionFlagValue(enabled))
  }

  function setClipboardOnFocus(enabled: boolean): void {
    settings.value = { ...settings.value, clipboardOnFocus: enabled }
    remember(COMPANION_CLIPBOARD_STORAGE_KEY, companionFlagValue(enabled))
  }

  return { target, size, windowKind, notice, noticeCause, companionWindow, settings, detach, close, retarget, setSize, setOpacity, setTextScale, setNotifications, setClipboardOnFocus }
})
