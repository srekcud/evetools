import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import { useTypeNames } from '@/composables/useTypeNames'
import type { IndustryProject } from '@/stores/industry/types'
import type { GroupProject } from '@/stores/group-industry/types'
import { groupProjectLabel } from '@/utils/groupProjectLabel'
import { searchProjectEntries } from '@/utils/commandPaletteSearch'

export type ProjectSourceStatus = 'hidden' | 'loading' | 'ready' | 'unavailable'
export type ProjectOrigin = 'industry' | 'group-industry'

export interface ProjectEntry {
  key: string
  label: string
  origin: ProjectOrigin
  isCompleted: boolean
  route: string
}

export interface ProjectSources {
  industry: boolean
  groupIndustry: boolean
}

interface ProjectSource<Project> {
  status: ProjectSourceStatus
  projects: Project[]
}

const COMPLETED_STATUS = 'completed'

function hiddenSource<Project>(): ProjectSource<Project> {
  return { status: 'hidden', projects: [] }
}

function loadingSource<Project>(): ProjectSource<Project> {
  return { status: 'loading', projects: [] }
}

export function useCommandPaletteProjects() {
  const { t } = useI18n()
  const { projectDisplayName } = useTypeNames()

  const industrySource = ref<ProjectSource<IndustryProject>>(hiddenSource())
  const groupIndustrySource = ref<ProjectSource<GroupProject>>(hiddenSource())
  let loadGeneration = 0

  function industryEntry(project: IndustryProject): ProjectEntry {
    return {
      key: `industry-${project.id}`,
      label: projectDisplayName(project),
      origin: 'industry',
      isCompleted: project.status === COMPLETED_STATUS,
      route: `/industry?tab=projects&project=${encodeURIComponent(project.id)}`,
    }
  }

  function groupIndustryEntry(project: GroupProject): ProjectEntry {
    return {
      key: `group-industry-${project.id}`,
      label: groupProjectLabel(project),
      origin: 'group-industry',
      isCompleted: project.status === COMPLETED_STATUS,
      route: `/group-industry/${encodeURIComponent(project.id)}`,
    }
  }

  const entries = computed((): ProjectEntry[] => {
    const all = [
      ...industrySource.value.projects.map(industryEntry),
      ...groupIndustrySource.value.projects.map(groupIndustryEntry),
    ]
    return [
      ...all.filter(entry => !entry.isCompleted),
      ...all.filter(entry => entry.isCompleted),
    ]
  })

  function searchProjects(query: string): ProjectEntry[] {
    return searchProjectEntries(entries.value, query)
  }

  const unavailableNotices = computed((): string[] => {
    const notices: string[] = []
    if (industrySource.value.status === 'unavailable') {
      notices.push(t('commandPalette.projects.industryUnavailable'))
    }
    if (groupIndustrySource.value.status === 'unavailable') {
      notices.push(t('commandPalette.projects.groupIndustryUnavailable'))
    }
    return notices
  })

  const isLoading = computed(() =>
    industrySource.value.status === 'loading' || groupIndustrySource.value.status === 'loading',
  )

  async function readSource<Project>(
    target: { value: ProjectSource<Project> },
    generation: number,
    read: () => Promise<Project[]>,
  ): Promise<void> {
    let source: ProjectSource<Project>
    try {
      source = { status: 'ready', projects: await read() }
    } catch {
      source = { status: 'unavailable', projects: [] }
    }
    if (generation === loadGeneration) {
      target.value = source
    }
  }

  async function loadProjects(sources: ProjectSources): Promise<void> {
    loadGeneration += 1
    const generation = loadGeneration
    industrySource.value = sources.industry ? loadingSource() : hiddenSource()
    groupIndustrySource.value = sources.groupIndustry ? loadingSource() : hiddenSource()
    await Promise.all([
      sources.industry
        ? readSource(industrySource, generation, async () =>
          (await apiRequest<{ projects: IndustryProject[] }>('/industry/projects')).projects)
        : Promise.resolve(),
      sources.groupIndustry
        ? readSource(groupIndustrySource, generation, () =>
          apiRequest<GroupProject[]>('/group-industry/projects'))
        : Promise.resolve(),
    ])
  }

  return { searchProjects, unavailableNotices, isLoading, loadProjects }
}
