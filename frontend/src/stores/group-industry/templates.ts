import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ProjectTemplate, SaveProjectTemplateInput } from './types'

export const useProjectTemplatesStore = defineStore('group-project-templates', () => {
  const templates = ref<ProjectTemplate[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTemplates(): Promise<void> {
    throw new Error('not implemented')
  }

  async function saveTemplate(_input: SaveProjectTemplateInput): Promise<ProjectTemplate> {
    throw new Error('not implemented')
  }

  async function updateTemplate(_id: string, _data: { name?: string, sharedWithCorporation?: boolean }): Promise<ProjectTemplate> {
    throw new Error('not implemented')
  }

  async function deleteTemplate(_id: string): Promise<void> {
    throw new Error('not implemented')
  }

  return { templates, loading, error, fetchTemplates, saveTemplate, updateTemplate, deleteTemplate }
})
