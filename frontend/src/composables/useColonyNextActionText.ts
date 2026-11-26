import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { NextAction, NextActionType } from '@/utils/colonyNextAction'

const MS_PER_SECOND = 1000

export function useColonyNextActionText() {
  const { t } = useI18n()
  const { formatDuration } = useFormatters()

  function labelOf(type: NextActionType): string {
    return t(`pi.nextAction.${type}`)
  }

  function isOverdue(action: NextAction, now: number): boolean {
    return action.at < now
  }

  function whenOf(action: NextAction, now: number): string {
    const duration = formatDuration(Math.floor(Math.abs(action.at - now) / MS_PER_SECOND))
    return t(isOverdue(action, now) ? 'pi.nextAction.ago' : 'pi.nextAction.in', { duration })
  }

  return { labelOf, isOverdue, whenOf }
}
