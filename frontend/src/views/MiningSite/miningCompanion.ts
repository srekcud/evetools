import type { MiningSite } from '@/stores/miningSite'

export type CompanionSizeKey = 'bar' | 'compact' | 'detail'
export type CompanionShortcut = 'size:bar' | 'size:compact' | 'size:detail' | 'paste' | 'detach'
export type CompanionTextScaleKey = 'S' | 'M' | 'L'

interface CompanionSize {
  width: number
  height: number
  key: string
}

interface CompanionPictureInPicture {
  requestWindow(options: { width: number; height: number }): Promise<Window>
}

type CompanionWindowHost = Window & { documentPictureInPicture?: CompanionPictureInPicture }

export const COMPANION_SIZES: Record<CompanionSizeKey, CompanionSize> = {
  bar: { width: 320, height: 110, key: 'b' },
  compact: { width: 340, height: 520, key: 'c' },
  detail: { width: 380, height: 1000, key: 'l' },
}
export const DEFAULT_COMPANION_SIZE: CompanionSizeKey = 'compact'
export const COMPANION_SIZE_STORAGE_KEY = 'miningCompanion.size'
export const COMPANION_WINDOW_NAME = 'evetools-compagnon'

export const COMPANION_PASTE_KEY = 'v'
export const COMPANION_DETACH_KEY = 'd'

export const COMPANION_OPACITY_MIN = 40
export const COMPANION_OPACITY_MAX = 100
export const COMPANION_OPACITY_DEFAULT = 100
export const COMPANION_OPACITY_STORAGE_KEY = 'miningCompanion.opacity'
export const COMPANION_TEXT_SCALES: Record<CompanionTextScaleKey, number> = { S: 0.9, M: 1, L: 1.2 }
export const DEFAULT_COMPANION_TEXT_SCALE: CompanionTextScaleKey = 'M'
export const COMPANION_TEXT_SCALE_STORAGE_KEY = 'miningCompanion.textScale'

const TEXT_FIELD_TAGS = ['input', 'textarea', 'select']

export class CompanionWindowError extends Error {
  constructor(readonly code: 'popup_blocked') {
    super(code)
  }
}

export interface CompanionWindowOpening {
  kind: 'pip' | 'popup'
  window: Window
  notice: 'popupFallback' | null
  cause: string | null
}

function isElementLike(target: EventTarget | null): target is Element {
  return target !== null && typeof (target as Element).tagName === 'string'
}

export function isTextEntryTarget(target: EventTarget | null): boolean {
  if (!isElementLike(target)) return false
  if (TEXT_FIELD_TAGS.includes(target.tagName.toLowerCase())) return true
  const editableAncestor = target.closest('[contenteditable]')
  return editableAncestor !== null && editableAncestor.getAttribute('contenteditable') !== 'false'
}

const SIZE_BY_SHORTCUT: Partial<Record<CompanionShortcut, CompanionSizeKey>> = {
  'size:bar': 'bar',
  'size:compact': 'compact',
  'size:detail': 'detail',
}

export function companionSizeOfShortcut(shortcut: CompanionShortcut): CompanionSizeKey | null {
  return SIZE_BY_SHORTCUT[shortcut] ?? null
}

export function companionShortcutOf(event: { key: string; ctrlKey: boolean; metaKey: boolean; altKey: boolean; target: EventTarget | null }): CompanionShortcut | null {
  if (event.ctrlKey || event.metaKey || event.altKey || isTextEntryTarget(event.target)) return null
  const key = event.key.toLowerCase()
  if (key === COMPANION_PASTE_KEY) return 'paste'
  if (key === COMPANION_DETACH_KEY) return 'detach'
  const sizeKey = (Object.keys(COMPANION_SIZES) as CompanionSizeKey[]).find(size => COMPANION_SIZES[size].key === key)
  return sizeKey === undefined ? null : `size:${sizeKey}`
}

function isCompanionSizeKey(value: string): value is CompanionSizeKey {
  return Object.keys(COMPANION_SIZES).includes(value)
}

function isCompanionTextScaleKey(value: string): value is CompanionTextScaleKey {
  return Object.keys(COMPANION_TEXT_SCALES).includes(value)
}

export function readCompanionSize(storage: Pick<Storage, 'getItem'>): CompanionSizeKey {
  const stored = storage.getItem(COMPANION_SIZE_STORAGE_KEY)
  return stored !== null && isCompanionSizeKey(stored) ? stored : DEFAULT_COMPANION_SIZE
}

export function clampCompanionOpacity(opacity: number): number {
  return Math.min(COMPANION_OPACITY_MAX, Math.max(COMPANION_OPACITY_MIN, opacity))
}

export function readCompanionOpacity(storage: Pick<Storage, 'getItem'>): number {
  const stored = storage.getItem(COMPANION_OPACITY_STORAGE_KEY)
  if (stored === null) return COMPANION_OPACITY_DEFAULT
  const opacity = Number.parseInt(stored, 10)
  return Number.isNaN(opacity) ? COMPANION_OPACITY_DEFAULT : clampCompanionOpacity(opacity)
}

export function readCompanionTextScale(storage: Pick<Storage, 'getItem'>): CompanionTextScaleKey {
  const stored = storage.getItem(COMPANION_TEXT_SCALE_STORAGE_KEY)
  return stored !== null && isCompanionTextScaleKey(stored) ? stored : DEFAULT_COMPANION_TEXT_SCALE
}

export function latestActiveSiteOf(sites: MiningSite[]): MiningSite | null {
  const activeSites = sites.filter(site => site.status === 'active')
  return activeSites.reduce<MiningSite | null>(
    (latest, site) => (latest === null || new Date(site.createdAt).getTime() > new Date(latest.createdAt).getTime() ? site : latest),
    null,
  )
}

function causeText(failure: unknown): string {
  return failure instanceof Error ? failure.message : String(failure)
}

export async function openCompanionWindow(host: Window, size: CompanionSizeKey): Promise<CompanionWindowOpening> {
  const { width, height } = COMPANION_SIZES[size]
  const pictureInPicture = (host as CompanionWindowHost).documentPictureInPicture
  let cause: string | null = null

  if (pictureInPicture !== undefined) {
    try {
      return { kind: 'pip', window: await pictureInPicture.requestWindow({ width, height }), notice: null, cause: null }
    } catch (refusal) {
      cause = causeText(refusal)
    }
  }

  const popup = host.open('', COMPANION_WINDOW_NAME, `popup,width=${width},height=${height}`)
  if (popup === null) throw new CompanionWindowError('popup_blocked')
  return { kind: 'popup', window: popup, notice: 'popupFallback', cause }
}
