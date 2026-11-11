<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CorporationRoleAccess } from '@/composables/useCorporationRoleAccess'
import CorporationAccessRequired from './CorporationAccessRequired.vue'

defineProps<{
  accesses: CorporationRoleAccess[]
  corporationLabel: (corporationId: number) => string
  recheckingIds: number[]
}>()

defineEmits<{ recheck: [corporationId: number] }>()

const { t } = useI18n()
</script>

<template>
  <div class="space-y-2">
    <details
      v-for="access in accesses"
      :key="access.corporationId"
      data-blocked-line
      :data-corporation-id="access.corporationId"
      :data-access-state="access.status"
      class="rounded-sm border border-slate-800 bg-slate-900/60"
    >
      <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
        <span class="truncate font-medium uppercase tracking-wider text-slate-300" :title="corporationLabel(access.corporationId)">{{ corporationLabel(access.corporationId) }}</span>
        <span class="text-amber-300">{{ t(`ledger.mining.moonTax.blockedLine.${access.status}`, { role: access.requiredRole }) }}</span>
      </summary>
      <div class="px-3 pb-3">
        <CorporationAccessRequired :access="access" :is-rechecking="recheckingIds.includes(access.corporationId)" @recheck="$emit('recheck', access.corporationId)" />
      </div>
    </details>
  </div>
</template>
