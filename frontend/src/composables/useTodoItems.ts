import { computed, type ComputedRef } from 'vue'

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

export function useTodoItems(): TodoItems {
  return {
    items: computed(() => []),
    isLoading: computed(() => false),
    isAllCaughtUp: computed(() => false),
  }
}
