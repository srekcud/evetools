import { useI18n } from 'vue-i18n'
import type { JobDisplayState } from '@/components/group-industry/jobDisplayState'

const JOB_DISPLAY_STATE_KEY_PREFIX = 'groupIndustry.jobs.displayState'

export function useJobDisplayLabel() {
  const { t } = useI18n()

  function jobDisplayLabel(state: JobDisplayState): string {
    return t(`${JOB_DISPLAY_STATE_KEY_PREFIX}.${state}`)
  }

  return { jobDisplayLabel }
}
