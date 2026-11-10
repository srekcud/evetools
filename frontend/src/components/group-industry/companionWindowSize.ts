export type CompanionWindowSizeKey = 'bar' | 'column'

export const COMPANION_WINDOW_SIZES: Record<CompanionWindowSizeKey, { width: number; height: number }> = {
  bar: { width: 440, height: 72 },
  column: { width: 360, height: 640 },
}

export const DEFAULT_COMPANION_WINDOW_SIZE: CompanionWindowSizeKey = 'column'
export const COMPANION_WINDOW_SIZE_STORAGE_KEY = 'groupCompanion.size'

function isSizeKey(value: string): value is CompanionWindowSizeKey {
  return Object.keys(COMPANION_WINDOW_SIZES).includes(value)
}

export function recallCompanionWindowSize(): CompanionWindowSizeKey {
  try {
    const stored = localStorage.getItem(COMPANION_WINDOW_SIZE_STORAGE_KEY)
    return stored !== null && isSizeKey(stored) ? stored : DEFAULT_COMPANION_WINDOW_SIZE
  } catch {
    return DEFAULT_COMPANION_WINDOW_SIZE
  }
}

export function rememberCompanionWindowSize(size: CompanionWindowSizeKey): void {
  try {
    localStorage.setItem(COMPANION_WINDOW_SIZE_STORAGE_KEY, size)
  } catch {
    return
  }
}
