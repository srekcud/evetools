import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { IndustryProjectStep, AvailableJob } from './types'
import { enrichStep, formatErrorMessage } from './compat'
import { useProjectsStore } from './projects'

export const useStepsStore = defineStore('industry-steps', () => {
  const projectsStore = useProjectsStore()
  const availableJobs = ref<AvailableJob[]>([])
  const availableJobsLoading = ref(false)

  function updateStepInProject(projectId: string, updated: IndustryProjectStep) {
    if (projectsStore.currentProject?.id === projectId && projectsStore.currentProject.steps) {
      const idx = projectsStore.currentProject.steps.findIndex(s => s.id === updated.id)
      if (idx !== -1) {
        projectsStore.currentProject.steps[idx] = updated
      }
    }
  }

  function updateMultipleStepsInProject(projectId: string, updatedSteps: IndustryProjectStep[]) {
    if (projectsStore.currentProject?.id === projectId && projectsStore.currentProject.steps) {
      for (const updated of updatedSteps) {
        const idx = projectsStore.currentProject.steps.findIndex(s => s.id === updated.id)
        if (idx !== -1) {
          projectsStore.currentProject.steps[idx] = updated
        }
      }
    }
  }

  async function toggleStepPurchased(projectId: string, stepId: string, purchased: boolean) {
    try {
      const updated = enrichStep(await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ purchased }),
        },
      ))
      updateStepInProject(projectId, updated)
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec de mise à jour de l\'étape')
      throw e
    }
  }

  async function toggleStepInStock(projectId: string, stepId: string, inStockQuantity: number) {
    try {
      const updated = enrichStep(await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ inStockQuantity }),
        },
      ))
      updateStepInProject(projectId, updated)
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec de mise à jour de l\'étape')
      throw e
    }
  }

  async function updateStep(projectId: string, stepId: string, data: Record<string, unknown>) {
    try {
      const result = await apiRequest<IndustryProjectStep | IndustryProjectStep[]>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify(data),
        },
      )
      // API may return single step or array (when cascade recalculation occurs)
      if (Array.isArray(result)) {
        const enriched = result.map(enrichStep)
        updateMultipleStepsInProject(projectId, enriched)
        return enriched[0]
      } else {
        const enriched = enrichStep(result)
        updateStepInProject(projectId, enriched)
        return enriched
      }
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec de mise à jour de l\'étape')
      throw e
    }
  }

  async function updateStepRuns(projectId: string, stepId: string, runs: number) {
    return updateStep(projectId, stepId, { runs })
  }

  async function updateStepMeLevel(projectId: string, stepId: string, meLevel: number) {
    return updateStep(projectId, stepId, { meLevel })
  }

  async function updateStepTeLevel(projectId: string, stepId: string, teLevel: number) {
    return updateStep(projectId, stepId, { teLevel })
  }

  async function updateStepStructure(projectId: string, stepId: string, structureConfigId: string | null) {
    return updateStep(projectId, stepId, { structureConfigId })
  }

  async function createStep(projectId: string, typeId: number, runs: number, meLevel?: number, teLevel?: number) {
    try {
      const body: Record<string, unknown> = { typeId, runs }
      if (meLevel !== undefined) body.meLevel = meLevel
      if (teLevel !== undefined) body.teLevel = teLevel
      const step = enrichStep(await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps`,
        {
          method: 'POST',
          body: JSON.stringify(body),
        },
      ))
      if (projectsStore.currentProject?.id === projectId && projectsStore.currentProject.steps) {
        projectsStore.currentProject.steps.push(step)
      }
      return step
    } catch (e) {
      projectsStore.error = e instanceof Error ? e.message : 'Failed to create step'
      throw e
    }
  }

  async function addChildJob(projectId: string, splitGroupId: string | null, stepId: string | null, runs: number) {
    try {
      const body: Record<string, unknown> = { runs }
      if (splitGroupId) body.splitGroupId = splitGroupId
      else if (stepId) body.stepId = stepId

      if (stepId) {
        const response = await apiRequest<{ newStep: IndustryProjectStep; updatedStep: IndustryProjectStep }>(
          `/industry/projects/${projectId}/steps`,
          {
            method: 'POST',
            body: JSON.stringify(body),
          },
        )
        if (projectsStore.currentProject?.id === projectId && projectsStore.currentProject.steps) {
          const idx = projectsStore.currentProject.steps.findIndex(s => s.id === stepId)
          if (idx !== -1) {
            projectsStore.currentProject.steps[idx] = response.updatedStep
          }
          projectsStore.currentProject.steps.push(response.newStep)
        }
        return response.newStep
      } else {
        const step = await apiRequest<IndustryProjectStep>(
          `/industry/projects/${projectId}/steps`,
          {
            method: 'POST',
            body: JSON.stringify(body),
          },
        )
        if (projectsStore.currentProject?.id === projectId && projectsStore.currentProject.steps) {
          projectsStore.currentProject.steps.push(step)
        }
        return step
      }
    } catch (e) {
      projectsStore.error = e instanceof Error ? e.message : 'Failed to add child job'
      throw e
    }
  }

  async function deleteStep(projectId: string, stepId: string) {
    try {
      await apiRequest(`/industry/projects/${projectId}/steps/${stepId}`, { method: 'DELETE' })
      if (projectsStore.currentProject?.id === projectId && projectsStore.currentProject.steps) {
        projectsStore.currentProject.steps = projectsStore.currentProject.steps.filter(s => s.id !== stepId)
      }
    } catch (e) {
      projectsStore.error = e instanceof Error ? e.message : 'Failed to delete step'
      throw e
    }
  }

  async function splitStep(projectId: string, stepId: string, numberOfJobs: number) {
    try {
      const steps = await apiRequest<IndustryProjectStep[]>(
        `/industry/projects/${projectId}/steps/${stepId}/split`,
        {
          method: 'POST',
          body: JSON.stringify({ numberOfJobs }),
        },
      )
      // Reload the project to get updated steps
      if (projectsStore.currentProject?.id === projectId) {
        await projectsStore.fetchProject(projectId)
      }
      return steps
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec du split')
      throw e
    }
  }

  async function mergeSteps(projectId: string, stepId: string) {
    try {
      const step = await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}/merge`,
        { method: 'POST', body: '{}' },
      )
      // Reload the project to get updated steps
      if (projectsStore.currentProject?.id === projectId) {
        await projectsStore.fetchProject(projectId)
      }
      return step
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec de la fusion')
      throw e
    }
  }

  async function fetchAvailableJobs(projectId: string) {
    availableJobsLoading.value = true
    try {
      availableJobs.value = await apiRequest<AvailableJob[]>(
        `/industry/projects/${projectId}/available-jobs`,
      )
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec du chargement des jobs ESI')
    } finally {
      availableJobsLoading.value = false
    }
  }

  async function linkJob(projectId: string, stepId: string, esiJobId: number) {
    try {
      const updated = enrichStep(await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}/link-job`,
        {
          method: 'POST',
          body: JSON.stringify({ esiJobId }),
        },
      ))
      updateStepInProject(projectId, updated)
      // Refresh available jobs to update link status
      await fetchAvailableJobs(projectId)
      return updated
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec de la liaison du job')
      throw e
    }
  }

  async function unlinkJob(projectId: string, matchId: string) {
    try {
      await apiRequest(`/industry/step-job-matches/${matchId}`, { method: 'DELETE' })
      // Reload the project to get updated steps
      await projectsStore.fetchProject(projectId)
      // Refresh available jobs
      await fetchAvailableJobs(projectId)
    } catch (e) {
      projectsStore.error = formatErrorMessage(e, 'Échec de la suppression du lien')
      throw e
    }
  }

  return {
    availableJobs,
    availableJobsLoading,
    toggleStepPurchased,
    toggleStepInStock,
    updateStep,
    updateStepRuns,
    updateStepMeLevel,
    updateStepTeLevel,
    updateStepStructure,
    createStep,
    addChildJob,
    deleteStep,
    splitStep,
    mergeSteps,
    fetchAvailableJobs,
    linkJob,
    unlinkJob,
  }
})
