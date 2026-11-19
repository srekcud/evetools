export const COMPANION_COLLAPSED_STORAGE_KEY = 'groupCompanion.collapsed'

export type CompanionSectionKey = 'free' | 'free:buy' | 'free:build' | 'mine' | 'mine:buy' | 'mine:build'

const SECTION_KEYS: CompanionSectionKey[] = ['free', 'free:buy', 'free:build', 'mine', 'mine:buy', 'mine:build']

function isSectionKey(value: unknown): value is CompanionSectionKey {
  return typeof value === 'string' && SECTION_KEYS.some(key => key === value)
}

export function recallCollapsedSections(): CompanionSectionKey[] {
  try {
    const stored = localStorage.getItem(COMPANION_COLLAPSED_STORAGE_KEY)
    if (stored === null) return []
    const parsed: unknown = JSON.parse(stored)
    return Array.isArray(parsed) ? parsed.filter(isSectionKey) : []
  } catch {
    return []
  }
}

export function rememberCollapsedSections(collapsed: CompanionSectionKey[]): void {
  try {
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, JSON.stringify(collapsed))
  } catch {
    return
  }
}
