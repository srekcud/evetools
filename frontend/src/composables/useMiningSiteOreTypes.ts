import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import type { OreValueRow, OreValueTableResponse } from '@/composables/useOreValueTable'

const ORE_VALUES_PATH = '/mining/ore-values?market=jita&side=buy'

export function useMiningSiteOreTypes() {
  const { t } = useI18n()
  const rows = ref<OreValueRow[]>([])
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    error.value = null
    try {
      rows.value = (await apiRequest<OreValueTableResponse>(ORE_VALUES_PATH)).rows
    } catch (cause) {
      rows.value = []
      error.value = cause instanceof Error ? cause.message : String(cause)
    }
  }

  function nameOf(typeId: number): string {
    const row = rows.value.find(candidate => candidate.typeId === typeId)
    return row === undefined ? t('common.unknownTypeName', { typeId }) : row.typeName
  }

  return { rows, error, load, nameOf }
}
