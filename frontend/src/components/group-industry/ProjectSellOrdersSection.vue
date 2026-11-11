<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ProjectSellOrdersCard from '@/components/group-industry/ProjectSellOrdersCard.vue'
import { useProjectSellOrdersStore } from '@/stores/group-industry/sellOrders'
import { useSyncStore } from '@/stores/sync'

const CLOCK_TICK_MS = 30_000
const CHARACTER_ORDERS_SYNC = 'character-orders'

const props = defineProps<{ projectId: string }>()

const store = useProjectSellOrdersStore()
const syncStore = useSyncStore()
const { sellOrders, error } = storeToRefs(store)
const now = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  void store.fetchSellOrders(props.projectId)
  clockTimer = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MS)
})

onBeforeUnmount(() => {
  if (clockTimer !== null) clearInterval(clockTimer)
})

watch(() => props.projectId, projectId => void store.fetchSellOrders(projectId))

watch(
  () => syncStore.getSyncProgress(CHARACTER_ORDERS_SYNC),
  (progress) => {
    if (progress?.status === 'completed') void store.fetchSellOrders(props.projectId)
  },
)
</script>

<template>
  <p v-if="error !== null" data-testid="sell-orders-error" class="mb-6 text-sm text-red-400">{{ error }}</p>
  <ProjectSellOrdersCard v-else-if="sellOrders !== null && Array.isArray(sellOrders.orders)" :sell-orders="sellOrders" :now="now" />
</template>
