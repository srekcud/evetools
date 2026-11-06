import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { groupProjectLabel } from '@/utils/groupProjectLabel'
import { searchProjectEntries } from '@/utils/commandPaletteSearch'

export type ProjectSourceStatus = 'hidden' | 'loading' | 'ready' | 'unavailable'

export interface ProjectEntry {
  key: string
  label: string
  isCompleted: boolean
  route: string
}

export interface ProjectLoadOptions {
  enabled: boolean
}

const COMPLETED_STATUS = 'completed'

export function useCommandPaletteProjects() {
  const { t } = useI18n()

  const status = ref<ProjectSourceStatus>('hidden')
  const projects = ref<GroupProject[]>([])
  let loadGeneration = 0

  function projectEntry(project: GroupProject): ProjectEntry {
    return {
      key: `project-${project.id}`,
      label: groupProjectLabel(project),
      isCompleted: project.status === COMPLETED_STATUS,
      route: `/group-industry/${encodeURIComponent(project.id)}`,
    }
  }

  const entries = computed((): ProjectEntry[] => {
    const all = projects.value.map(projectEntry)
    return [
      ...all.filter(entry => !entry.isCompleted),
      ...all.filter(entry => entry.isCompleted),
    ]
  })

  function searchProjects(query: string): ProjectEntry[] {
    return searchProjectEntries(entries.value, query)
  }

  const unavailableNotices = computed((): string[] =>
    status.value === 'unavailable' ? [t('commandPalette.projects.groupIndustryUnavailable')] : [],
  )

  const isLoading = computed(() => status.value === 'loading')

  async function loadProjects(source: ProjectLoadOptions): Promise<void> {
    loadGeneration += 1
    const generation = loadGeneration
    if (!source.enabled) {
      status.value = 'hidden'
      projects.value = []
      return
    }
    status.value = 'loading'
    projects.value = []
    let loaded: GroupProject[]
    try {
      loaded = await apiRequest<GroupProject[]>('/industry/projects')
    } catch {
      if (generation === loadGeneration) {
        status.value = 'unavailable'
      }
      return
    }
    if (generation === loadGeneration) {
      projects.value = loaded
      status.value = 'ready'
    }
  }

  return { searchProjects, unavailableNotices, isLoading, loadProjects }
}
