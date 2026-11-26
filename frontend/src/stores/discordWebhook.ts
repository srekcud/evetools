import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useDiscordWebhookStore = defineStore('discordWebhook', () => {
  const configured = ref<boolean | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const fieldError = ref<string | null>(null)

  async function fetchStatus(): Promise<void> {}

  async function save(_url: string): Promise<boolean> {
    return false
  }

  async function remove(): Promise<void> {}

  async function sendTest(): Promise<boolean> {
    return false
  }

  function clearError(): void {}

  return { configured, isLoading, error, fieldError, fetchStatus, save, remove, sendTest, clearError }
})
