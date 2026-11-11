import type { User } from '@/types'
import type { PlanetaryStats } from '@/stores/planetary'
import type { MarketAlert } from '@/stores/market'

interface CharacterToken {
  hasValidToken: boolean
  hasMissingScopes: boolean
}

export const VALID_TOKEN: CharacterToken = { hasValidToken: true, hasMissingScopes: false }
export const INVALID_TOKEN: CharacterToken = { hasValidToken: false, hasMissingScopes: false }
export const MISSING_SCOPES_TOKEN: CharacterToken = { hasValidToken: false, hasMissingScopes: true }

export function todoUser(tokens: { authStatus?: string; ilyra?: CharacterToken; doran?: CharacterToken } = {}): User {
  const ilyra = {
    id: '00000000-0000-4000-8000-000000230001',
    eveCharacterId: 2112000001,
    name: 'Ilyra Castell',
    corporationId: 98000230,
    corporationName: 'Castell Extraction',
    allianceId: null,
    allianceName: null,
    isMain: true,
    lastSyncAt: '2026-10-26T08:00:00+00:00',
    ...(tokens.ilyra ?? VALID_TOKEN),
  }
  const doran = {
    id: '00000000-0000-4000-8000-000000230002',
    eveCharacterId: 2112000002,
    name: 'Doran Vex',
    corporationId: 98000230,
    corporationName: 'Castell Extraction',
    allianceId: null,
    allianceName: null,
    isMain: false,
    lastSyncAt: '2026-10-26T08:05:00+00:00',
    ...(tokens.doran ?? VALID_TOKEN),
  }

  return {
    id: '00000000-0000-4000-8000-000000230000',
    authStatus: tokens.authStatus ?? 'valid',
    mainCharacter: ilyra,
    characters: [ilyra, doran],
    corporationId: 98000230,
    corporationName: 'Castell Extraction',
  }
}

export function planetaryStats(extractors: { expiredExtractors?: number; expiringExtractors?: number } = {}): PlanetaryStats {
  const expiredExtractors = extractors.expiredExtractors ?? 0
  const expiringExtractors = extractors.expiringExtractors ?? 0

  return {
    totalColonies: 3,
    totalExtractors: 8,
    activeExtractors: 8 - expiredExtractors,
    expiringExtractors,
    expiredExtractors,
    totalFactories: 14,
    estimatedDailyIsk: 6_750_000,
    nearestExpiry: '2026-10-28T06:00:00+00:00',
  }
}

export const NO_COLONY_STATS: PlanetaryStats = {
  totalColonies: 0,
  totalExtractors: 0,
  activeExtractors: 0,
  expiringExtractors: 0,
  expiredExtractors: 0,
  totalFactories: 0,
  estimatedDailyIsk: 0,
  nearestExpiry: null,
}

function priceAlert(typeId: number, typeName: string, threshold: number, status: MarketAlert['status']): MarketAlert {
  return {
    id: `00000000-0000-4000-8000-0000002300${typeId}`,
    typeId,
    typeName,
    direction: 'above',
    threshold,
    priceSource: 'jita_sell',
    status,
    currentPrice: status === 'triggered' ? threshold + 1.5 : threshold - 0.75,
    triggeredAt: status === 'triggered' ? '2026-10-26T14:30:00+00:00' : null,
    triggeredPrice: status === 'triggered' ? threshold : null,
    createdAt: '2026-10-20T10:00:00+00:00',
  }
}

export const ACTIVE_TRITANIUM_ALERT = priceAlert(34, 'Tritanium', 5.5, 'active')
export const TRIGGERED_PYERITE_ALERT = priceAlert(35, 'Pyerite', 12.25, 'triggered')
export const TRIGGERED_MEXALLON_ALERT = priceAlert(36, 'Mexallon', 48.9, 'triggered')
export const EXPIRED_ISOGEN_ALERT = priceAlert(37, 'Isogen', 95.4, 'expired')
export const TRIGGERED_NOCXIUM_ALERT = priceAlert(38, 'Nocxium', 640, 'triggered')

export const PENDING = 'pending'

export const ACKNOWLEDGEMENTS_ENDPOINT = '/me/bridge/acknowledgements'

export interface AcknowledgementRow {
  itemKey: string
  acknowledgedCount: number
}

export function acknowledgement(itemKey: string, acknowledgedCount: number): AcknowledgementRow {
  return { itemKey, acknowledgedCount }
}

export type SourceAnswer = PlanetaryStats | MarketAlert[] | AcknowledgementRow[] | Error | typeof PENDING

function answer(source: SourceAnswer): Promise<unknown> {
  if (source === PENDING) return new Promise(() => {})
  if (source instanceof Error) return Promise.reject(source)
  return Promise.resolve(source)
}

interface SourceAnswers {
  user: User | null
  planetaryStats: SourceAnswer[]
  marketAlerts: SourceAnswer[]
  acknowledgements?: SourceAnswer[]
  acknowledgementWriteError?: Error
}

export function sourcesAnswering(answers: SourceAnswers) {
  const planetaryStatsQueue = [...answers.planetaryStats]
  const marketAlertsQueue = [...answers.marketAlerts]
  const acknowledgementsQueue = [...(answers.acknowledgements ?? [[]])]
  const written = new Map<string, number | null>()
  const next = (queue: SourceAnswer[]): SourceAnswer => (queue.length > 1 ? queue.shift() as SourceAnswer : queue[0])

  function acknowledgementsAfterWrites(source: SourceAnswer): SourceAnswer {
    if (!Array.isArray(source)) return source
    const stored = new Map((source as AcknowledgementRow[]).map(row => [row.itemKey, row.acknowledgedCount]))
    written.forEach((count, itemKey) => {
      if (count === null) stored.delete(itemKey)
      else stored.set(itemKey, count)
    })
    return [...stored].map(([itemKey, acknowledgedCount]) => acknowledgement(itemKey, acknowledgedCount))
  }

  function writeAcknowledgement(endpoint: string, options: RequestInit | undefined): Promise<unknown> {
    if (answers.acknowledgementWriteError) return Promise.reject(answers.acknowledgementWriteError)
    const method = options?.method?.toUpperCase()
    if (method === 'DELETE') written.set(endpoint.slice(ACKNOWLEDGEMENTS_ENDPOINT.length + 1), null)
    if (method === 'POST' && typeof options?.body === 'string') {
      const body = JSON.parse(options.body) as { itemKey: string; count: number }
      written.set(body.itemKey, body.count)
    }
    return Promise.resolve(null)
  }

  return (endpoint: string, options?: RequestInit): Promise<unknown> => {
    if (endpoint === '/me') return Promise.resolve(answers.user)
    if (endpoint === '/planetary/stats') return answer(next(planetaryStatsQueue))
    if (endpoint === '/market/alerts') return answer(next(marketAlertsQueue))
    if (endpoint === ACKNOWLEDGEMENTS_ENDPOINT && (options?.method ?? 'GET').toUpperCase() === 'GET') {
      return answer(acknowledgementsAfterWrites(next(acknowledgementsQueue)))
    }
    if (endpoint.startsWith(ACKNOWLEDGEMENTS_ENDPOINT)) return writeAcknowledgement(endpoint, options)
    return new Promise(() => {})
  }
}

export function acknowledgementWrites(calls: ReadonlyArray<readonly [string, RequestInit?]>): Array<{ endpoint: string; method: string; body: unknown }> {
  return calls
    .filter(([endpoint, options]) => endpoint.startsWith(ACKNOWLEDGEMENTS_ENDPOINT) && (options?.method ?? 'GET').toUpperCase() !== 'GET')
    .map(([endpoint, options]) => ({
      endpoint,
      method: (options?.method ?? 'GET').toUpperCase(),
      body: typeof options?.body === 'string' ? JSON.parse(options.body) : null,
    }))
}

export function serverError(): Error {
  return new Error('Internal Server Error')
}
