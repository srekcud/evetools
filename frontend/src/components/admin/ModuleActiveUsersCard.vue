<script setup lang="ts">
import type { AdminModuleActiveUsers } from '@/stores/admin'

defineProps<{
  modules: AdminModuleActiveUsers[]
  activeUsersLast30Days: number
}>()
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800" />
</template>
