import { onBeforeUnmount, ref } from 'vue'

const COPIED_FEEDBACK_MILLISECONDS = 2000

export function useCopyFeedback() {
  const copied = ref(false)
  const failed = ref(false)
  let copiedTimer: ReturnType<typeof setTimeout> | undefined

  async function copy(text: string): Promise<void> {
    failed.value = false
    try {
      await navigator.clipboard.writeText(text)
      copied.value = true
      clearTimeout(copiedTimer)
      copiedTimer = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MILLISECONDS)
    } catch {
      copied.value = false
      failed.value = true
    }
  }

  onBeforeUnmount(() => clearTimeout(copiedTimer))

  return { copied, failed, copy }
}
