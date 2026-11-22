import { FLOW_COLUMNS, type FlowCardState, type FlowGrid } from '@/components/group-industry/projectFlow'

export type FlowStateCount = { state: FlowCardState; count: number }

const STORAGE_KEY_PREFIX = 'group-industry-flow-'
const COLLAPSED_VALUE = 'closed'
const EXPANDED_VALUE = 'open'
const SUMMARY_STATE_ORDER: readonly FlowCardState[] = ['toBring', 'inTransit', 'onSite', 'consumed', 'inProduction', 'out', 'toBuy', 'toLaunch', 'expected', 'sold', 'unknown']

export function flowCollapsedStorageKey(projectId: string): string {
  return `${STORAGE_KEY_PREFIX}${projectId}`
}

export function readFlowCollapsed(storage: Pick<Storage, 'getItem'>, projectId: string): boolean {
  try {
    return storage.getItem(flowCollapsedStorageKey(projectId)) === COLLAPSED_VALUE
  } catch {
    return false
  }
}

export function writeFlowCollapsed(storage: Pick<Storage, 'setItem'>, projectId: string, collapsed: boolean): void {
  try {
    storage.setItem(flowCollapsedStorageKey(projectId), collapsed ? COLLAPSED_VALUE : EXPANDED_VALUE)
  } catch {
    return
  }
}

export function flowStateSummary(grid: FlowGrid): FlowStateCount[] {
  const countByState = new Map<FlowCardState, number>()
  for (const row of grid.rows) {
    for (const column of FLOW_COLUMNS) {
      for (const card of row.cells[column]) {
        const previousCount = countByState.get(card.state)
        countByState.set(card.state, previousCount === undefined ? 1 : previousCount + 1)
      }
    }
  }
  return SUMMARY_STATE_ORDER.flatMap((state) => {
    const count = countByState.get(state)
    return count === undefined ? [] : [{ state, count }]
  })
}
