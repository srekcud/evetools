import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ProjectCourier } from './types'

export const useGroupCourierStore = defineStore('group-courier', () => {
  const courier = ref<ProjectCourier | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchCourier(projectId: string): Promise<void> {
    void projectId
  }

  async function linkContract(projectId: string, contractId: number): Promise<void> {
    void projectId
    void contractId
  }

  async function unlinkContract(projectId: string): Promise<void> {
    void projectId
  }

  return { courier, loading, error, fetchCourier, linkContract, unlinkContract }
})
