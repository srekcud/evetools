import type { FlowCardSource } from './projectFlow'

export const SOURCE_CLASSES: Record<FlowCardSource, string> = {
  stock: 'border-slate-600 text-slate-400',
  blueprint: 'border-violet-400/50 text-violet-300',
  invention: 'border-blue-400/50 text-blue-300',
}
