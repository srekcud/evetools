import type { ChainStatus } from '@/stores/group-industry/types'

export const CHAIN_STATUSES: readonly ChainStatus[] = ['done', 'active', 'todo', 'blocked', 'unknown']

const CHIP_CLASSES: Record<ChainStatus, string> = {
  done: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
  active: 'bg-cyan-500/15 text-cyan-400 border-cyan-500/30',
  todo: 'bg-slate-700/60 text-slate-400 border-slate-600',
  blocked: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
  unknown: 'bg-transparent text-violet-300 border-dashed border-violet-400',
}

const DOT_CLASSES: Record<ChainStatus, string> = {
  done: 'bg-emerald-400',
  active: 'bg-cyan-400',
  todo: 'bg-slate-500',
  blocked: 'bg-amber-400',
  unknown: 'bg-violet-400',
}

const EDGE_CLASSES: Record<ChainStatus, string> = {
  done: 'border-l-emerald-500',
  active: 'border-l-cyan-500',
  todo: 'border-l-slate-600',
  blocked: 'border-l-amber-500',
  unknown: 'border-l-violet-400',
}

export function chainStatusChipClass(status: ChainStatus): string {
  return CHIP_CLASSES[status]
}

export function chainStatusDotClass(status: ChainStatus): string {
  return DOT_CLASSES[status]
}

export function chainStatusEdgeClass(status: ChainStatus): string {
  return EDGE_CLASSES[status]
}
