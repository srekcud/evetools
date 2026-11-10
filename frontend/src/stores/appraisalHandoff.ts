import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAppraisalHandoffStore = defineStore('appraisalHandoff', () => {
  const pendingText = ref<string | null>(null)

  function send(text: string): void {
    if (text.trim() === '') return
    pendingText.value = text
  }

  function take(): string | null {
    const text = pendingText.value
    pendingText.value = null
    return text
  }

  return { pendingText, send, take }
})
