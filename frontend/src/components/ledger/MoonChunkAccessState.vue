<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatChunkTime, type CorporationMiningExtractions } from '@/composables/useCorporationMiningExtractions'
import type { CorporationRoleAccess } from '@/composables/useCorporationRoleAccess'
import CorporationAccessRequired from './CorporationAccessRequired.vue'

defineProps<{
  entry: CorporationMiningExtractions
  access: CorporationRoleAccess | null
  isRechecking: boolean
  timeZone: string
}>()

defineEmits<{ recheck: [] }>()

const { t } = useI18n()
</script>

<template>
  <CorporationAccessRequired v-if="access !== null" :access="access" :is-rechecking="isRechecking" @recheck="$emit('recheck')">
    <span v-if="entry.lastSuccessAt !== null" class="text-xs text-slate-400">
      {{ t('ledger.mining.moonChunks.lastSuccess') }}
      <time data-last-success-at :datetime="entry.lastSuccessAt">{{ formatChunkTime(entry.lastSuccessAt, timeZone) }}</time>
    </span>
  </CorporationAccessRequired>
  <p
    v-else
    :data-access-state="entry.access"
    role="status"
    class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"
  >
    <span aria-hidden="true">⚠ </span>{{ t(`ledger.mining.moonChunks.access.${entry.access}`) }}
    <template v-if="entry.lastSuccessAt !== null">
      · {{ t('ledger.mining.moonChunks.lastSuccess') }}
      <time data-last-success-at :datetime="entry.lastSuccessAt">{{ formatChunkTime(entry.lastSuccessAt, timeZone) }}</time>
    </template>
  </p>
</template>
