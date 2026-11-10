import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { MiningValuationForm, MiningValuationMarket, MiningValuationSide } from '@/stores/miningOperation'

export interface ValuationSource {
  market: MiningValuationMarket
  side: MiningValuationSide
  form: MiningValuationForm
  structureName: string | null
}

export interface MiningValuationProfile {
  market: MiningValuationMarket
  side: MiningValuationSide
  form: MiningValuationForm
  structureId: number | null
  structureName: string | null
  exportTax: number
  persisted: boolean
}

export type MiningValuationProfilePatch = Partial<Omit<MiningValuationProfile, 'persisted'>> & { clearStructure?: boolean }

export const useMiningValuationProfileStore = defineStore('miningValuationProfile', () => {
  const profile = ref<MiningValuationProfile | null>(null)

  async function load(): Promise<void> {
  }

  async function update(_patch: MiningValuationProfilePatch): Promise<void> {
  }

  return { profile, load, update }
})
