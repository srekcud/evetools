export type ChainView = 'flow' | 'tree'

export type ChainFocusRequest = { nodeId: string | null }

export const CHAIN_VIEWS: readonly ChainView[] = ['flow', 'tree']

export const DEFAULT_CHAIN_VIEW: ChainView = 'flow'

export const CHAIN_VIEW_STORAGE_KEY = 'groupIndustry.chainView'

function isChainView(value: unknown): value is ChainView {
  return CHAIN_VIEWS.some(view => view === value)
}

export function recallChainView(): ChainView {
  try {
    const stored = localStorage.getItem(CHAIN_VIEW_STORAGE_KEY)
    return isChainView(stored) ? stored : DEFAULT_CHAIN_VIEW
  } catch {
    return DEFAULT_CHAIN_VIEW
  }
}

export function rememberChainView(view: ChainView): void {
  try {
    localStorage.setItem(CHAIN_VIEW_STORAGE_KEY, view)
  } catch {
    return
  }
}
