import { ref } from 'vue'

export interface Toast {
  id: number
  message: string
  type: 'success' | 'warning' | 'error'
  visible: boolean
}

let toastCounter = 0

const toasts = ref<Toast[]>([])

export function useToast() {
  function showToast(message: string, type: 'success' | 'warning' | 'error' = 'success'): void {
    const id = ++toastCounter
    const toast: Toast = { id, message, type, visible: false }
    toasts.value.push(toast)

    // Animate in
    requestAnimationFrame(() => {
      const found = toasts.value.find(t => t.id === id)
      if (found) found.visible = true
    })

    // Auto dismiss
    setTimeout(() => {
      const found = toasts.value.find(t => t.id === id)
      if (found) found.visible = false
      setTimeout(() => {
        toasts.value = toasts.value.filter(t => t.id !== id)
      }, 300)
    }, 3500)
  }

  return {
    toasts,
    showToast,
  }
}
