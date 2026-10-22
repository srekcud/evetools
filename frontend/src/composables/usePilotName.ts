import { useI18n } from 'vue-i18n'

export function usePilotName() {
  const { t } = useI18n()

  function pilotName(name: string | null): string {
    return name ?? t('common.deletedPilot')
  }

  return { pilotName }
}
