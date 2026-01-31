import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useRateLimitStore = defineStore('rateLimit', () => {
  const rateLimitedUntil = ref<number | null>(null)
  const currentTime = ref(Date.now())
  const cooldownSeconds = 60 // ESI rate limit cooldown
  let intervalId: number | null = null

  const isRateLimited = computed(() => {
    if (rateLimitedUntil.value === null) return false
    return currentTime.value < rateLimitedUntil.value
  })

  const remainingSeconds = computed(() => {
    if (rateLimitedUntil.value === null) return 0
    const remaining = Math.ceil((rateLimitedUntil.value - currentTime.value) / 1000)
    return Math.max(0, remaining)
  })

  function startCountdown() {
    if (intervalId !== null) return
    intervalId = window.setInterval(() => {
      currentTime.value = Date.now()
      // Auto-clear when countdown reaches 0
      if (rateLimitedUntil.value && currentTime.value >= rateLimitedUntil.value) {
        clearRateLimit()
      }
    }, 1000)
  }

  function stopCountdown() {
    if (intervalId !== null) {
      clearInterval(intervalId)
      intervalId = null
    }
  }

  function setRateLimited() {
    rateLimitedUntil.value = Date.now() + (cooldownSeconds * 1000)
    currentTime.value = Date.now()
    startCountdown()
  }

  function clearRateLimit() {
    rateLimitedUntil.value = null
    stopCountdown()
  }

  return {
    isRateLimited,
    remainingSeconds,
    setRateLimited,
    clearRateLimit,
  }
})
