import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectTemplate, SaveProjectTemplateInput } from './types'

const TEMPLATES_PATH = '/industry/project-templates'

function messageOf(cause: unknown, fallback: string): string {
  return cause instanceof Error ? cause.message : fallback
}

export const useProjectTemplatesStore = defineStore('group-project-templates', () => {
  const templates = ref<ProjectTemplate[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTemplates(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      templates.value = await apiRequest<ProjectTemplate[]>(TEMPLATES_PATH)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch project templates')
    } finally {
      loading.value = false
    }
  }

  async function saveTemplate(input: SaveProjectTemplateInput): Promise<ProjectTemplate> {
    error.value = null
    try {
      const created = await apiRequest<ProjectTemplate>(TEMPLATES_PATH, {
        method: 'POST',
        body: JSON.stringify(input),
      })
      templates.value = [created, ...templates.value]
      return created
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to save project template')
      throw cause
    }
  }

  async function updateTemplate(id: string, data: { name?: string, sharedWithCorporation?: boolean }): Promise<ProjectTemplate> {
    error.value = null
    try {
      const updated = await apiRequest<ProjectTemplate>(`${TEMPLATES_PATH}/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(data),
      })
      templates.value = templates.value.map(template => (template.id === id ? updated : template))
      return updated
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to update project template')
      throw cause
    }
  }

  async function deleteTemplate(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest(`${TEMPLATES_PATH}/${id}`, { method: 'DELETE' })
      templates.value = templates.value.filter(template => template.id !== id)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to delete project template')
      throw cause
    }
  }

  return { templates, loading, error, fetchTemplates, saveTemplate, updateTemplate, deleteTemplate }
})
