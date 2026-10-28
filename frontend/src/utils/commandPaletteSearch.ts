import type { NavigationEntry } from '@/composables/useNavigationEntries'

export function normalizeSearchText(_text: string): string {
  throw new Error('not implemented')
}

export function searchNavigationEntries(_entries: readonly NavigationEntry[], _query: string): NavigationEntry[] {
  throw new Error('not implemented')
}
