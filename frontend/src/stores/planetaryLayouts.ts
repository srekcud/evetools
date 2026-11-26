import { defineStore } from 'pinia'
import { ref } from 'vue'

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

export const usePlanetaryLayoutsStore = defineStore('planetaryLayouts', () => {
  const layouts = ref<ColonyLayout[]>([])

  async function load(): Promise<void> {}

  async function importLayout(_text: string, _name?: string): Promise<ColonyLayoutError[]> {
    return []
  }

  async function rename(_id: string, _name: string): Promise<void> {}

  async function remove(_id: string): Promise<void> {}

  return { layouts, load, importLayout, rename, remove }
})
