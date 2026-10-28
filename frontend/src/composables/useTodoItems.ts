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
}

export interface TodoItems {
  items: ComputedRef<TodoItem[]>
  isLoading: ComputedRef<boolean>
  isAllCaughtUp: ComputedRef<boolean>
}

type SourceState<T> =
  | { status: 'loading' }
  | { status: 'loaded'; value: T }
  | { status: 'failed' }

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
  return { labelKey, count, characterNames, level, link }
}

export function useTodoItems(): TodoItems {
  const authStore = useAuthStore()
  const syncStore = useSyncStore()
  const planetaryStats = useSource<PlanetaryStats>('/planetary/stats')
  const priceAlerts = useSource<MarketAlert[]>('/market/alerts')

  planetaryStats.reload()
  priceAlerts.reload()

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
    if (state.value.expiredExtractors > 0) items.push(item('expiredExtractors', state.value.expiredExtractors, 'critical', PLANETARY_LINK))
    if (state.value.expiringExtractors > 0) items.push(item('expiringExtractors', state.value.expiringExtractors, 'warning', PLANETARY_LINK))
    return items
  })

  const priceAlertsItem = computed<TodoItem | null>(() => {
    const state = priceAlerts.state.value
    if (state.status === 'loading') return null
    if (state.status === 'failed') return item('unknownPriceAlerts', null, null, PRICE_ALERTS_LINK)

    const triggeredCount = state.value.filter(alert => alert.status === 'triggered').length
    return triggeredCount > 0 ? item('triggeredPriceAlerts', triggeredCount, 'info', PRICE_ALERTS_LINK) : null
  })

  const items = computed<TodoItem[]>(() => [
    charactersItem.value,
    ...extractorItems.value,
    priceAlertsItem.value,
  ].filter((todoItem): todoItem is TodoItem => todoItem !== null))

  const isLoading = computed(() => planetaryStats.state.value.status === 'loading' || priceAlerts.state.value.status === 'loading')
  const isAllCaughtUp = computed(() => !isLoading.value && items.value.length === 0)

  return { items, isLoading, isAllCaughtUp }
}
