import type { NavigationEntry } from '@/composables/useNavigationEntries'

export const NAVIGATION_RESULT_LIMIT = 10
export const PROJECT_RESULT_LIMIT = 5
export const TYPE_RESULT_LIMIT = 8

const COMBINING_MARKS = /[̀-ͯ]/g
const WHITESPACE_RUN = /\s+/g

type MatchRank = 0 | 1 | 2

interface RankedEntry {
  entry: NavigationEntry
  rank: MatchRank
  declarationIndex: number
}

export function normalizeSearchText(text: string): string {
  return text
    .normalize('NFD')
    .replace(COMBINING_MARKS, '')
    .toLowerCase()
    .replace(WHITESPACE_RUN, ' ')
    .trim()
}

function rankLabel(normalizedLabel: string, normalizedQuery: string, queryWords: string[]): MatchRank | null {
  if (!queryWords.every(word => normalizedLabel.includes(word))) {
    return null
  }
  if (normalizedLabel.startsWith(normalizedQuery)) {
    return 0
  }
  if (normalizedLabel.split(' ').some(labelWord => labelWord.startsWith(queryWords[0]))) {
    return 1
  }
  return 2
}

function rankEntry(entry: NavigationEntry, normalizedQuery: string, queryWords: string[]): MatchRank | null {
  return rankLabel(normalizeSearchText(entry.label), normalizedQuery, queryWords)
    ?? rankLabel(normalizeSearchText(entry.aliasLabel), normalizedQuery, queryWords)
}

export function searchNavigationEntries(entries: readonly NavigationEntry[], query: string): NavigationEntry[] {
  const normalizedQuery = normalizeSearchText(query)
  if (normalizedQuery === '') {
    return entries.filter(entry => !entry.isTab).slice(0, NAVIGATION_RESULT_LIMIT)
  }
  const queryWords = normalizedQuery.split(' ')
  const rankedEntries: RankedEntry[] = []
  entries.forEach((entry, declarationIndex) => {
    const rank = rankEntry(entry, normalizedQuery, queryWords)
    if (rank !== null) {
      rankedEntries.push({ entry, rank, declarationIndex })
    }
  })
  return rankedEntries
    .sort((first, second) => first.rank - second.rank || first.declarationIndex - second.declarationIndex)
    .slice(0, NAVIGATION_RESULT_LIMIT)
    .map(({ entry }) => entry)
}

export function searchProjectEntries<Entry extends { label: string }>(entries: readonly Entry[], query: string): Entry[] {
  const normalizedQuery = normalizeSearchText(query)
  if (normalizedQuery === '') {
    return []
  }
  const queryWords = normalizedQuery.split(' ')
  return entries
    .filter(entry => {
      const normalizedLabel = normalizeSearchText(entry.label)
      return queryWords.every(word => normalizedLabel.includes(word))
    })
    .slice(0, PROJECT_RESULT_LIMIT)
}
