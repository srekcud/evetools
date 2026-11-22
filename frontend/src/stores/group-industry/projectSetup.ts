import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { BomItem, ProjectWorkStations, SetupMarketWrite, SourcingDecisions } from './types'

export type SetupSaveInput = {
  workStations: Record<string, string>
  blacklistTypeIds: number[] | null
  markets: SetupMarketWrite[]
}

type SourcingQuery = { buy: number[]; build: number[] }

class SetupLineNotFoundError extends Error {
  constructor(typeId: number) {
    super(`No material line of the project buys the type ${typeId}`)
  }
}

function queryStringOf(query: SourcingQuery | undefined): string {
  if (query === undefined) return ''
  const parts: string[] = []
  if (query.buy.length > 0) parts.push(`buy=${query.buy.join(',')}`)
  if (query.build.length > 0) parts.push(`build=${query.build.join(',')}`)
  return parts.length === 0 ? '' : `?${parts.join('&')}`
}

export const useProjectSetupStore = defineStore('group-project-setup', () => {
  const workStations = ref<ProjectWorkStations | null>(null)
  const decisions = ref<SourcingDecisions | null>(null)
  const baselineTotal = ref<number | null>(null)
  const workStationsError = ref<string | null>(null)
  const decisionsError = ref<string | null>(null)
  const saveError = ref<string | null>(null)
  const saving = ref(false)
  let latestDecisionsRequest = 0
  let baselineProjectId: string | null = null

  async function fetchWorkStations(projectId: string): Promise<void> {
    workStationsError.value = null
    try {
      workStations.value = await apiRequest<ProjectWorkStations>(`/industry/projects/${projectId}/work-stations`)
    } catch (e) {
      workStations.value = null
      workStationsError.value = e instanceof Error ? e.message : 'Failed to fetch work stations'
    }
  }

  async function fetchSourcingDecisions(projectId: string, query?: SourcingQuery): Promise<void> {
    const request = ++latestDecisionsRequest
    decisionsError.value = null
    try {
      const fetched = await apiRequest<SourcingDecisions>(`/industry/projects/${projectId}/sourcing-decisions${queryStringOf(query)}`)
      if (request !== latestDecisionsRequest) return
      decisions.value = fetched
      if (baselineProjectId !== projectId) {
        baselineProjectId = projectId
        baselineTotal.value = fetched.totals.total
      }
    } catch (e) {
      if (request !== latestDecisionsRequest) return
      decisions.value = null
      decisionsError.value = e instanceof Error ? e.message : 'Failed to fetch sourcing decisions'
    }
  }

  async function saveSetup(projectId: string, input: SetupSaveInput): Promise<boolean> {
    const projectPath = `/industry/projects/${projectId}`
    saving.value = true
    saveError.value = null
    try {
      await apiRequest(projectPath, { method: 'PATCH', body: JSON.stringify({ workStations: input.workStations }) })
      if (input.blacklistTypeIds !== null) {
        await apiRequest(projectPath, { method: 'PATCH', body: JSON.stringify({ blacklistTypeIds: input.blacklistTypeIds }) })
      }
      if (input.markets.length > 0) {
        const lines = await apiRequest<BomItem[]>(`${projectPath}/bom`)
        for (const write of input.markets) {
          const line = lines.find(candidate => !candidate.isJob && candidate.typeId === write.typeId)
          if (line === undefined) throw new SetupLineNotFoundError(write.typeId)
          await apiRequest(`${projectPath}/bom/${line.id}/sourcing-market`, { method: 'POST', body: JSON.stringify({ market: write.market }) })
        }
      }
      return true
    } catch (e) {
      saveError.value = e instanceof Error ? e.message : 'Failed to save the project setup'
      return false
    } finally {
      saving.value = false
    }
  }

  return { workStations, decisions, baselineTotal, workStationsError, decisionsError, saveError, saving, fetchWorkStations, fetchSourcingDecisions, saveSetup }
})
