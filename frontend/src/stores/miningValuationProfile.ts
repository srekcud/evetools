import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
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

const PROFILE_ENDPOINT = '/mining/valuation-profile'
const BROWSER_STRUCTURE_KEY = 'miningSelectedStructure'
const BROWSER_EXPORT_TAX_KEY = 'miningExportTax'

function readBrowserStructure(): MiningValuationProfilePatch {
  const stored = localStorage.getItem(BROWSER_STRUCTURE_KEY)
  if (stored === null || stored === '') return {}
  try {
    const structure = JSON.parse(stored) as { id: number | null; name: string }
    if (typeof structure.id !== 'number' || typeof structure.name !== 'string') return {}
    return { structureId: structure.id, structureName: structure.name }
  } catch (e) {
    console.error('Unreadable mining structure kept in the browser, ignored:', e)
    return {}
  }
}

function readBrowserExportTax(): MiningValuationProfilePatch {
  const stored = localStorage.getItem(BROWSER_EXPORT_TAX_KEY)
  if (stored === null || stored === '') return {}
  const exportTax = parseFloat(stored)
  if (Number.isNaN(exportTax)) {
    console.error('Unreadable mining export tax kept in the browser, ignored:', stored)
    return {}
  }
  return { exportTax: Math.max(0, exportTax) }
}

export const useMiningValuationProfileStore = defineStore('miningValuationProfile', () => {
  const profile = ref<MiningValuationProfile | null>(null)
  let pendingLoad: Promise<void> | null = null

  async function patchProfile(patch: MiningValuationProfilePatch): Promise<void> {
    profile.value = await apiRequest<MiningValuationProfile>(PROFILE_ENDPOINT, {
      method: 'PATCH',
      body: JSON.stringify(patch),
    })
  }

  async function importBrowserSettings(): Promise<void> {
    const browserSettings = { ...readBrowserStructure(), ...readBrowserExportTax() }
    if (Object.keys(browserSettings).length === 0) return
    try {
      await patchProfile({ market: 'jita', ...browserSettings })
    } catch (e) {
      console.error('Failed to import the mining settings kept in the browser:', e)
      return
    }
    localStorage.removeItem(BROWSER_STRUCTURE_KEY)
    localStorage.removeItem(BROWSER_EXPORT_TAX_KEY)
  }

  async function fetchProfile(): Promise<void> {
    try {
      const fetched = await apiRequest<MiningValuationProfile>(PROFILE_ENDPOINT)
      profile.value = fetched
      if (!fetched.persisted) await importBrowserSettings()
    } catch (e) {
      console.error('Failed to load the mining valuation profile:', e)
    }
  }

  function load(): Promise<void> {
    pendingLoad ??= fetchProfile().finally(() => { pendingLoad = null })
    return pendingLoad
  }

  async function update(patch: MiningValuationProfilePatch): Promise<void> {
    await patchProfile(patch)
  }

  return { profile, load, update }
})
