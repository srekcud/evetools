import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { MarketAlert } from '@/stores/market'
import type { PlanetaryStats } from '@/stores/planetary'

export type TodoLevel = 'critical' | 'warning' | 'info'

export type TodoLabelKey =
  | 'charactersToReconnect'
  | 'atLeastCharactersToReconnect'
  | 'expiredExtractors'
  | 'expiringExtractors'
  | 'triggeredPriceAlerts'
  | 'unknownCharacters'
  | 'unknownExtractors'
  | 'unknownPriceAlerts'

export interface TodoItem {
  labelKey: TodoLabelKey
  count: number | null
  characterNames: string[]
  level: TodoLevel | null
  link: string
  acknowledgeable: boolean
}

export interface TodoItems {
  planetaryStats: Ref<SourceState<PlanetaryStats>>
  items: ComputedRef<TodoItem[]>
  isLoading: ComputedRef<boolean>
  isAllCaughtUp: ComputedRef<boolean>
  acknowledge: (item: TodoItem) => Promise<void>
  acknowledgeAll: () => Promise<void>
}

export type SourceState<T> =
  | { status: 'loading' }
  | { status: 'loaded'; value: T }
  | { status: 'failed' }

export type AcknowledgeableKey = 'expiredExtractors' | 'expiringExtractors' | 'triggeredPriceAlerts'

interface AcknowledgementRow {
  itemKey: string
  acknowledgedCount: number
}

const ACKNOWLEDGEMENTS_ENDPOINT = '/me/bridge/acknowledgements'
const CHARACTERS_LINK = '/characters'
const PLANETARY_LINK = '/planetary'
const PRICE_ALERTS_LINK = '/market?tab=alerts'

function useSource<T>(endpoint: string): { state: Ref<SourceState<T>>; reload: () => Promise<void> } {
  const state = ref<SourceState<T>>({ status: 'loading' }) as Ref<SourceState<T>>

  async function reload(): Promise<void> {
    try {
      state.value = { status: 'loaded', value: await apiRequest<T>(endpoint) }
    } catch (error) {
      console.error(`Failed to load ${endpoint}:`, error)
      state.value = { status: 'failed' }
    }
  }

  return { state, reload }
}

function item(labelKey: TodoLabelKey, count: number | null, level: TodoLevel | null, link: string, characterNames: string[] = []): TodoItem {
  return { labelKey, count, characterNames, level, link, acknowledgeable: false }
}

function acknowledgeableItem(labelKey: AcknowledgeableKey, count: number, level: TodoLevel, link: string): TodoItem {
  return { ...item(labelKey, count, level, link), acknowledgeable: true }
}

export function useTodoItems(): TodoItems {
  const authStore = useAuthStore()
  const syncStore = useSyncStore()
  const planetaryStats = useSource<PlanetaryStats>('/planetary/stats')
  const priceAlerts = useSource<MarketAlert[]>('/market/alerts')
  const acknowledgements = useSource<AcknowledgementRow[]>(ACKNOWLEDGEMENTS_ENDPOINT)
  const removalsInFlight = new Set<string>()

  planetaryStats.reload()
  priceAlerts.reload()
  acknowledgements.reload()

  watch(
    () => syncStore.planetaryProgress,
    progress => {
      if (progress?.status === 'completed') planetaryStats.reload()
    },
  )
  watch(() => syncStore.alertMessages['planetary-expiry'], message => {
    if (message) planetaryStats.reload()
  })
  watch(() => syncStore.alertMessages['market-price'], message => {
    if (message) priceAlerts.reload()
  })

  const charactersItem = computed<TodoItem | null>(() => {
    const user = authStore.user
    if (!user) return item('unknownCharacters', null, null, CHARACTERS_LINK)

    const names = user.characters.filter(character => !character.hasValidToken).map(character => character.name)
    if (user.authStatus === 'invalid') {
      return item('atLeastCharactersToReconnect', Math.max(1, names.length), 'critical', CHARACTERS_LINK, names)
    }
    return names.length > 0 ? item('charactersToReconnect', names.length, 'critical', CHARACTERS_LINK, names) : null
  })

  const extractorItems = computed<TodoItem[]>(() => {
    const state = planetaryStats.state.value
    if (state.status === 'loading') return []
    if (state.status === 'failed') return [item('unknownExtractors', null, null, PLANETARY_LINK)]

    const items: TodoItem[] = []
    if (state.value.expiredExtractors > 0) items.push(acknowledgeableItem('expiredExtractors', state.value.expiredExtractors, 'critical', PLANETARY_LINK))
    if (state.value.expiringExtractors > 0) items.push(acknowledgeableItem('expiringExtractors', state.value.expiringExtractors, 'warning', PLANETARY_LINK))
    return items
  })

  const priceAlertsItem = computed<TodoItem | null>(() => {
    const state = priceAlerts.state.value
    if (state.status === 'loading') return null
    if (state.status === 'failed') return item('unknownPriceAlerts', null, null, PRICE_ALERTS_LINK)

    const triggeredCount = state.value.filter(alert => alert.status === 'triggered').length
    return triggeredCount > 0 ? acknowledgeableItem('triggeredPriceAlerts', triggeredCount, 'info', PRICE_ALERTS_LINK) : null
  })

  const acknowledgedCounts = computed<Map<string, number> | null>(() => {
    const state = acknowledgements.state.value
    if (state.status === 'loading') return null
    if (state.status === 'failed') return new Map()
    return new Map(state.value.map(row => [row.itemKey, row.acknowledgedCount]))
  })

  function isVisible(todoItem: TodoItem): boolean {
    if (!todoItem.acknowledgeable) return true
    const counts = acknowledgedCounts.value
    if (counts === null) return false
    const acknowledgedCount = counts.get(todoItem.labelKey)
    return acknowledgedCount === undefined || todoItem.count === null || todoItem.count > acknowledgedCount
  }

  const items = computed<TodoItem[]>(() => [
    charactersItem.value,
    ...extractorItems.value,
    priceAlertsItem.value,
  ].filter((todoItem): todoItem is TodoItem => todoItem !== null && isVisible(todoItem)))

  const isLoading = computed(() => [planetaryStats.state.value, priceAlerts.state.value, acknowledgements.state.value].some(state => state.status === 'loading'))
  const isAllCaughtUp = computed(() => !isLoading.value && items.value.length === 0)

  const sourceCounts = computed<Record<AcknowledgeableKey, number | null>>(() => {
    const extractors = planetaryStats.state.value
    const alerts = priceAlerts.state.value
    return {
      expiredExtractors: extractors.status === 'loaded' ? extractors.value.expiredExtractors : null,
      expiringExtractors: extractors.status === 'loaded' ? extractors.value.expiringExtractors : null,
      triggeredPriceAlerts: alerts.status === 'loaded' ? alerts.value.filter(alert => alert.status === 'triggered').length : null,
    }
  })

  const settledAcknowledgementKeys = computed<string[]>(() => {
    const state = acknowledgements.state.value
    if (state.status !== 'loaded') return []
    return state.value
      .map(row => row.itemKey)
      .filter(itemKey => sourceCounts.value[itemKey as AcknowledgeableKey] === 0)
  })

  async function removeAcknowledgement(itemKey: string): Promise<void> {
    if (removalsInFlight.has(itemKey)) return
    removalsInFlight.add(itemKey)
    try {
      await apiRequest(`${ACKNOWLEDGEMENTS_ENDPOINT}/${itemKey}`, { method: 'DELETE' })
      await acknowledgements.reload()
    } catch (error) {
      console.error(`Failed to remove the acknowledgement of ${itemKey}:`, error)
    } finally {
      removalsInFlight.delete(itemKey)
    }
  }

  watch(settledAcknowledgementKeys, itemKeys => itemKeys.forEach(removeAcknowledgement), { immediate: true })

  async function writeAcknowledgement(todoItem: TodoItem): Promise<void> {
    try {
      await apiRequest(ACKNOWLEDGEMENTS_ENDPOINT, {
        method: 'POST',
        body: JSON.stringify({ itemKey: todoItem.labelKey, count: todoItem.count }),
      })
    } catch (error) {
      console.error(`Failed to acknowledge ${todoItem.labelKey}:`, error)
    }
  }

  async function acknowledge(todoItem: TodoItem): Promise<void> {
    await writeAcknowledgement(todoItem)
    await acknowledgements.reload()
  }

  async function acknowledgeAll(): Promise<void> {
    for (const todoItem of items.value.filter(line => line.acknowledgeable)) {
      await writeAcknowledgement(todoItem)
    }
    await acknowledgements.reload()
  }

  return { planetaryStats: planetaryStats.state, items, isLoading, isAllCaughtUp, acknowledge, acknowledgeAll }
}
