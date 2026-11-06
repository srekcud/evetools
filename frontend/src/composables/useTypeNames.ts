import { useI18n } from 'vue-i18n'

export function useTypeNames() {
  const { t } = useI18n()

  function typeNameLabel(typeName: string | null, typeId: number): string {
    return typeName ?? t('common.unknownTypeName', { typeId })
  }

  return { typeNameLabel }
}
