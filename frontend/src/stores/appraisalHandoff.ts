import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAppraisalHandoffStore = defineStore('appraisalHandoff', () => {
  const pendingText = ref<string | null>(null)

  function send(text: string): void {
    void text
  }

  function take(): string | null {
    return null
  }

  return { pendingText, send, take }
})
