import type { ChainNode, ChainStatus } from '@/stores/group-industry/types'
import { CHAIN_STATUSES } from './chainStatus'

export const CHAIN_STATUS_FILTER_STORAGE_KEY = 'groupIndustry.chainStatusFilter'

function isChainStatus(value: unknown): value is ChainStatus {
  return CHAIN_STATUSES.some(status => status === value)
}

export function filterChainNodes(nodes: ChainNode[], statuses: readonly ChainStatus[]): ChainNode[] {
  if (statuses.length === 0) return nodes
  return nodes.filter(node => statuses.includes(node.status))
}

export function toggleChainStatus(selected: readonly ChainStatus[], status: ChainStatus): ChainStatus[] {
  return selected.includes(status) ? selected.filter(candidate => candidate !== status) : [...selected, status]
}

export function recallChainStatusFilter(): ChainStatus[] {
  try {
    const stored = localStorage.getItem(CHAIN_STATUS_FILTER_STORAGE_KEY)
    if (stored === null) return []
    const parsed: unknown = JSON.parse(stored)
    return Array.isArray(parsed) && parsed.every(isChainStatus) ? parsed : []
  } catch {
    return []
  }
}

export function rememberChainStatusFilter(statuses: readonly ChainStatus[]): void {
  try {
    localStorage.setItem(CHAIN_STATUS_FILTER_STORAGE_KEY, JSON.stringify(statuses))
  } catch {
    return
  }
}
