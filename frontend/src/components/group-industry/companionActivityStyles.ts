import type { ActivityGroup } from './companionTasks'

export type ActivityStyle = { text: string; stripe: string; tint: string; bar: string }

export const ACTIVITY_STYLES: Record<ActivityGroup, ActivityStyle> = {
  copy: { text: 'text-violet-300', stripe: 'border-l-violet-400', tint: 'from-violet-400/10', bar: 'bg-violet-400' },
  invention: { text: 'text-blue-300', stripe: 'border-l-blue-400', tint: 'from-blue-400/10', bar: 'bg-blue-400' },
  reaction: { text: 'text-orange-300', stripe: 'border-l-orange-400', tint: 'from-orange-400/10', bar: 'bg-orange-400' },
  manufacturing: { text: 'text-cyan-300', stripe: 'border-l-cyan-400', tint: 'from-cyan-400/10', bar: 'bg-cyan-400' },
  material: { text: 'text-slate-300', stripe: 'border-l-slate-400', tint: 'from-slate-400/10', bar: 'bg-slate-400' },
  unknown: { text: 'text-slate-500', stripe: 'border-l-slate-600', tint: 'from-slate-600/10', bar: 'bg-slate-600' },
}
