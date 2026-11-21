import { useI18n } from 'vue-i18n'

const ESI_JOB_STATUS_KEY_PREFIX = 'groupIndustry.jobs.esiStatus'
const UNKNOWN_ESI_JOB_STATUS = 'unknown'

export function useEsiJobStatusLabel() {
  const { t, te } = useI18n()

  function esiJobStatusLabel(status: string): string {
    const key = `${ESI_JOB_STATUS_KEY_PREFIX}.${status}`

    return t(te(key) ? key : `${ESI_JOB_STATUS_KEY_PREFIX}.${UNKNOWN_ESI_JOB_STATUS}`)
  }

  return { esiJobStatusLabel }
}
