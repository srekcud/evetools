import { useI18n } from 'vue-i18n'

export function useFlowReasonLabel(): (reason: string) => string {
  const { t, te } = useI18n()

  return (reason) => {
    const key = `groupIndustry.flow.unknownReasons.${reason}`
    return te(key) ? t(key) : reason
  }
}
