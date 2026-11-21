import { onBeforeUnmount, onMounted, ref } from 'vue'

const NOW_TICK_MS = 1000

export function useNow() {
  const now = ref(Date.now())
  let timer: ReturnType<typeof setInterval> | null = null

  onMounted(() => {
    timer = setInterval(() => {
      now.value = Date.now()
    }, NOW_TICK_MS)
  })

  onBeforeUnmount(() => {
    if (timer !== null) clearInterval(timer)
  })

  return now
}
