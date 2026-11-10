import { computed } from 'vue'
import {
  useMiningValuationProfileStore,
  type MiningValuationProfile,
  type MiningValuationProfilePatch,
} from '@/stores/miningValuationProfile'
import type { MiningValuationForm, MiningValuationMarket, MiningValuationSide } from '@/stores/miningOperation'

export interface SelectedStructure {
  id: number | null
  name: string
}

const NO_STRUCTURE: SelectedStructure = { id: null, name: '' }
const DEFAULT_MARKET: MiningValuationMarket = 'jita'
const DEFAULT_SIDE: MiningValuationSide = 'buy'
const DEFAULT_FORM: MiningValuationForm = 'compressed'
const DEFAULT_EXPORT_TAX = 0

export function useMiningValuationSettings() {
  const store = useMiningValuationProfileStore()
  if (store.profile === null) void store.load()

  const profile = computed<MiningValuationProfile | null>(() => store.profile)
  const market = computed(() => profile.value?.market ?? DEFAULT_MARKET)
  const side = computed(() => profile.value?.side ?? DEFAULT_SIDE)
  const form = computed(() => profile.value?.form ?? DEFAULT_FORM)
  const exportTax = computed(() => profile.value?.exportTax ?? DEFAULT_EXPORT_TAX)
  const selectedStructure = computed<SelectedStructure>(() => {
    const current = profile.value
    if (current === null || current.structureId === null) return { ...NO_STRUCTURE }
    return { id: current.structureId, name: current.structureName ?? '' }
  })
  const selectedStructureId = computed(() => selectedStructure.value.id)

  async function write(patch: MiningValuationProfilePatch): Promise<void> {
    try {
      await store.update(patch)
    } catch (e) {
      console.error('Failed to save the mining valuation profile:', e)
    }
  }

  function selectStructure(structure: SelectedStructure): void {
    void write({ structureId: structure.id, structureName: structure.name })
  }

  function clearStructure(): void {
    void write({ clearStructure: true })
  }

  function setExportTax(value: number): void {
    void write({ exportTax: Math.max(0, value) })
  }

  function setMarketAndSide(newMarket: MiningValuationMarket, newSide: MiningValuationSide): void {
    void write({ market: newMarket, side: newSide })
  }

  function setForm(newForm: MiningValuationForm): void {
    void write({ form: newForm })
  }

  return {
    market,
    side,
    form,
    selectedStructure,
    selectedStructureId,
    exportTax,
    selectStructure,
    clearStructure,
    setExportTax,
    setMarketAndSide,
    setForm,
  }
}
