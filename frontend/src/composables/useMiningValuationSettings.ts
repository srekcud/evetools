import { computed, ref } from 'vue'
import type { MiningValuationForm, MiningValuationMarket, MiningValuationSide } from '@/stores/miningOperation'

export const SELECTED_STRUCTURE_STORAGE_KEY = 'miningSelectedStructure'
export const EXPORT_TAX_STORAGE_KEY = 'miningExportTax'
export const DEFAULT_EXPORT_TAX = 1200

export interface SelectedStructure {
  id: number | null
  name: string
}

const NO_STRUCTURE: SelectedStructure = { id: null, name: '' }

export function readSelectedStructure(): SelectedStructure {
  const stored = localStorage.getItem(SELECTED_STRUCTURE_STORAGE_KEY)
  return stored === null || stored === '' ? { ...NO_STRUCTURE } : JSON.parse(stored) as SelectedStructure
}

export function readExportTax(): number {
  const stored = localStorage.getItem(EXPORT_TAX_STORAGE_KEY)
  return stored === null || stored === '' ? DEFAULT_EXPORT_TAX : parseFloat(stored)
}

export function useMiningValuationSettings() {
  const selectedStructure = ref<SelectedStructure>(readSelectedStructure())
  const exportTax = ref(readExportTax())
  const selectedStructureId = computed(() => selectedStructure.value.id)
  const market = ref<MiningValuationMarket>('jita')
  const side = ref<MiningValuationSide>('buy')
  const form = ref<MiningValuationForm>('compressed')

  function selectStructure(structure: SelectedStructure): void {
    selectedStructure.value = { id: structure.id, name: structure.name }
    localStorage.setItem(SELECTED_STRUCTURE_STORAGE_KEY, JSON.stringify(selectedStructure.value))
  }

  function clearStructure(): void {
    selectedStructure.value = { ...NO_STRUCTURE }
    localStorage.removeItem(SELECTED_STRUCTURE_STORAGE_KEY)
  }

  function setExportTax(value: number): void {
    exportTax.value = Math.max(0, value)
    localStorage.setItem(EXPORT_TAX_STORAGE_KEY, exportTax.value.toString())
  }

  return { market, side, form, selectedStructure, selectedStructureId, exportTax, selectStructure, clearStructure, setExportTax }
}
