import { defineStore } from 'pinia'
import { ref } from 'vue'
import { isStationProjectOpen, type StationProjectSource } from '@/components/group-industry/stationView'
import { apiRequest } from '@/services/api'
import type { BomItem, GroupProject, GroupSale, PlacedAction, ProjectOverview, TypeStationQuantities } from './types'

const PROJECTS_PATH = '/industry/projects'
const OVERVIEWS_PATH = '/industry/projects/overview'
const ACTIONS_PATH = '/industry/place-actions'

export interface StationUnreadableProject {
  projectId: string
  projectName: string | null
}

function projectPath(projectId: string, resource: string): string {
  return `${PROJECTS_PATH}/${projectId}/${resource}`
}

function requireArray<T>(read: T[], what: string): T[] {
  if (!Array.isArray(read)) {
    throw new Error(`Unexpected ${what} response`)
  }
  return read
}

export const useGroupStationViewStore = defineStore('group-station-view', () => {
  const sources = ref<StationProjectSource[]>([])
  const actions = ref<PlacedAction[]>([])
  const unreadable = ref<StationUnreadableProject[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function readProject(project: GroupProject, overview: ProjectOverview | undefined): Promise<StationProjectSource> {
    const [quantities, bomItems, sales] = await Promise.all([
      apiRequest<TypeStationQuantities[]>(projectPath(project.id, 'station-quantities')),
      apiRequest<BomItem[]>(projectPath(project.id, 'bom')),
      apiRequest<GroupSale[]>(projectPath(project.id, 'sales')),
    ])
    return {
      project,
      myRole: overview === undefined ? null : overview.myRole,
      ownerName: overview === undefined ? project.ownerCharacterName : overview.ownerName,
      quantities: requireArray(quantities, 'station quantities'),
      bomItems: requireArray(bomItems, 'bom'),
      sales: requireArray(sales, 'sales'),
    }
  }

  async function load(): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const [projects, overviews, placed] = await Promise.all([
        apiRequest<GroupProject[]>(PROJECTS_PATH),
        apiRequest<ProjectOverview[]>(OVERVIEWS_PATH),
        apiRequest<PlacedAction[]>(ACTIONS_PATH),
      ])
      const overviewByProject = new Map(requireArray(overviews, 'projects overview').map(overview => [overview.projectId, overview]))
      const openProjects = requireArray(projects, 'projects').filter(project => isStationProjectOpen(project.status))
      const reads = await Promise.allSettled(openProjects.map(project => readProject(project, overviewByProject.get(project.id))))
      if (request !== latestRequest) {
        return
      }
      sources.value = reads.flatMap(read => (read.status === 'fulfilled' ? [read.value] : []))
      unreadable.value = reads.flatMap((read, index) => (read.status === 'rejected' ? [{ projectId: openProjects[index].id, projectName: openProjects[index].name }] : []))
      actions.value = requireArray(placed, 'place actions')
      loaded.value = true
    } catch (e) {
      if (request === latestRequest) {
        sources.value = []
        unreadable.value = []
        actions.value = []
        error.value = e instanceof Error ? e.message : 'Failed to read the station'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { sources, actions, unreadable, loading, loaded, error, load }
})
