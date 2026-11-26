import { useI18n } from 'vue-i18n'
import type { FactoryGap, FactoryGapState } from '@/utils/planetaryFollow'

const GAP_STATE_CLASSES: Record<FactoryGapState, string> = {
  missing: 'text-red-400',
  free: 'text-sky-400',
  ok: 'text-emerald-400',
  unknown: 'text-slate-500',
}

export function useFactoryGapDisplay() {
  const { t } = useI18n()

  function gapText(gap: FactoryGap): string {
    const key = `pi.planner.follow.gap.${gap.state}`
    if (gap.gap === null) return t(key)
    const count = Math.abs(gap.gap)
    return t(key, { n: count }, count)
  }

  function gapClass(state: FactoryGapState): string {
    return GAP_STATE_CLASSES[state]
  }

  return { gapText, gapClass }
}
