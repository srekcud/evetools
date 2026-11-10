<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useBridgeBalance } from '@/composables/useBridgeBalance'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'

const BALANCE_LINK = '/balance'

const { t } = useI18n()
const { formatIsk } = useFormatters()
const balance = useBridgeBalance()
</script>

<template>
  <section
    data-testid="dashboard-balance"
    :data-state="balance.status.value"
    class="bg-slate-900 rounded-2xl p-5 border border-slate-800"
  >
    <div class="mb-3 flex items-center justify-between gap-3">
      <h3 class="min-w-0 truncate text-sm font-semibold text-slate-200 uppercase tracking-wider">{{ t('dashboard.bridge.balance.title') }}</h3>
      <RouterLink
        :to="BALANCE_LINK"
        class="shrink-0 text-xs font-medium uppercase tracking-wider text-cyan-400 hover:text-cyan-300 transition-colors"
      >{{ t('dashboard.bridge.balance.link') }}</RouterLink>
    </div>
    <div v-if="balance.status.value === 'loading'" class="flex items-center py-2">
      <LoadingSpinner size="sm" />
    </div>
    <p v-else-if="balance.status.value === 'unknown'" class="text-sm text-amber-500">{{ t('dashboard.bridge.unknown') }}</p>
    <dl v-else class="grid grid-cols-2 gap-3 md:grid-cols-4">
      <div
        v-for="row in balance.rows.value"
        :key="row.id"
        data-testid="dashboard-balance-row"
        class="rounded-lg border border-slate-800 bg-slate-800/40 px-4 py-3"
      >
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t(`dashboard.bridge.balance.${row.id}`) }}</dt> <dd :class="['mt-1 font-mono text-lg font-semibold', row.amount !== null && row.amount < 0 ? 'text-red-400' : 'text-slate-100']">
          <UnknownValue v-if="row.amount === null" />
          <template v-else>{{ formatIsk(row.amount) }}</template>
        </dd>
      </div>
    </dl>
  </section>
</template>
