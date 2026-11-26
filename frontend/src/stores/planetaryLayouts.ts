import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest, ValidationError } from '@/services/api'

export interface ColonyLayoutProduct {
  typeId: number
  typeName: string | null
  tier: string | null
}

export interface ColonyLayoutExtracted {
  typeId: number
  typeName: string | null
}

export interface ColonyLayoutSummary {
  planetTypeId: number
  planetTypeName: string | null
  commandCenterLevel: number
  buildings: {
    extractorControlUnits: number
    extractorHeads: number
    processors: number
    storages: number
    launchpads: number
  }
  links: number
  routes: number
  load: { cpu: number, powergrid: number } | null
  products: ColonyLayoutProduct[]
  extracted: ColonyLayoutExtracted[]
  skills: Array<{ name: string, level: number }>
}

export interface ColonyLayout {
  id: string
  name: string
  summary: ColonyLayoutSummary
  gameTemplate: Record<string, unknown>
  createdAt: string
}

export interface ColonyLayoutError {
  path: string
  code: string
}

const LAYOUTS_ENDPOINT = '/planetary/layouts'
const REQUEST_FAILED_ERROR: ColonyLayoutError = { path: '$', code: 'request_failed' }

export const usePlanetaryLayoutsStore = defineStore('planetaryLayouts', () => {
  const layouts = ref<ColonyLayout[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      layouts.value = await apiRequest<ColonyLayout[]>(LAYOUTS_ENDPOINT)
    } catch {
      error.value = i18n.global.t('pi.layouts.loadError')
    } finally {
      isLoading.value = false
    }
  }

  async function importLayout(text: string, name?: string): Promise<ColonyLayoutError[]> {
    const body = name === undefined ? { template: text } : { template: text, name }

    try {
      const created = await apiRequest<ColonyLayout>(LAYOUTS_ENDPOINT, { method: 'POST', body: JSON.stringify(body) })
      layouts.value = [created, ...layouts.value]
      return []
    } catch (caught) {
      if (caught instanceof ValidationError) {
        return caught.violations.map(violation => ({ path: violation.propertyPath, code: violation.message }))
      }
      return [REQUEST_FAILED_ERROR]
    }
  }

  async function rename(id: string, name: string): Promise<boolean> {
    error.value = null

    try {
      const renamed = await apiRequest<ColonyLayout>(`${LAYOUTS_ENDPOINT}/${id}`, { method: 'PATCH', body: JSON.stringify({ name }) })
      layouts.value = layouts.value.map(layout => layout.id === id ? renamed : layout)
      return true
    } catch {
      error.value = i18n.global.t('pi.layouts.renameError')
      return false
    }
  }

  async function remove(id: string): Promise<boolean> {
    error.value = null

    try {
      await apiRequest<null>(`${LAYOUTS_ENDPOINT}/${id}`, { method: 'DELETE' })
      layouts.value = layouts.value.filter(layout => layout.id !== id)
      return true
    } catch {
      error.value = i18n.global.t('pi.layouts.deleteError')
      return false
    }
  }

  return { layouts, isLoading, error, load, importLayout, rename, remove }
})
