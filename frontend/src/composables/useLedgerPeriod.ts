import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

export const LEDGER_PERIOD_STORAGE_KEY = 'ledgerSelectedDays'
const DEFAULT_PERIOD_DAYS = 30
const PRESET_DAYS = [7, 14, 30, 60, 90] as const
const MILLISECONDS_PER_DAY = 1000 * 60 * 60 * 24

export function useLedgerPeriod() {
  const { t } = useI18n()
  const stored = localStorage.getItem(LEDGER_PERIOD_STORAGE_KEY)
  const selectedDays = ref(stored === null || stored === '' ? DEFAULT_PERIOD_DAYS : parseInt(stored))

  const periodOptions = computed(() => PRESET_DAYS.map(days => ({ value: days, label: t('ledger.period.nDays', { n: days }) })))

  const yearToDateDays = computed(() => {
    const now = new Date()
    const startOfYear = new Date(now.getFullYear(), 0, 1)
    return Math.ceil((now.getTime() - startOfYear.getTime()) / MILLISECONDS_PER_DAY)
  })

  watch(selectedDays, days => localStorage.setItem(LEDGER_PERIOD_STORAGE_KEY, days.toString()))

  return { selectedDays, periodOptions, yearToDateDays }
}
