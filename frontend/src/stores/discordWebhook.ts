import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ValidationError, apiRequest } from '@/services/api'

const WEBHOOK_PATH = '/me/discord-webhook'
const WEBHOOK_TEST_PATH = '/me/discord-webhook/test'

interface DiscordWebhookStatus {
  configured: boolean
}

function messageOf(cause: unknown, fallback: string): string {
  return cause instanceof Error ? cause.message : fallback
}

export const useDiscordWebhookStore = defineStore('discordWebhook', () => {
  const configured = ref<boolean | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const fieldError = ref<string | null>(null)

  async function fetchStatus(): Promise<void> {
    isLoading.value = true
    error.value = null
    try {
      const status = await apiRequest<DiscordWebhookStatus>(WEBHOOK_PATH)
      configured.value = status.configured
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to read the Discord webhook status')
    } finally {
      isLoading.value = false
    }
  }

  async function save(url: string): Promise<boolean> {
    isLoading.value = true
    error.value = null
    fieldError.value = null
    try {
      const status = await apiRequest<DiscordWebhookStatus>(WEBHOOK_PATH, {
        method: 'PUT',
        body: JSON.stringify({ url }),
      })
      configured.value = status.configured
      return true
    } catch (cause) {
      if (cause instanceof ValidationError) {
        fieldError.value = cause.getFieldError('url') ?? cause.message
      } else {
        error.value = messageOf(cause, 'Failed to save the Discord webhook')
      }
      return false
    } finally {
      isLoading.value = false
    }
  }

  async function remove(): Promise<void> {
    isLoading.value = true
    error.value = null
    try {
      await apiRequest(WEBHOOK_PATH, { method: 'DELETE' })
      configured.value = false
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to remove the Discord webhook')
    } finally {
      isLoading.value = false
    }
  }

  async function sendTest(): Promise<boolean> {
    isLoading.value = true
    error.value = null
    try {
      await apiRequest(WEBHOOK_TEST_PATH, { method: 'POST' })
      return true
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to send the Discord test message')
      return false
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
    fieldError.value = null
  }

  return { configured, isLoading, error, fieldError, fetchStatus, save, remove, sendTest, clearError }
})
