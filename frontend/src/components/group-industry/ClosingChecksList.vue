<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { buildClosingChecks } from '@/components/group-industry/closingChecks'
import type { ClosingChecksData } from '@/stores/group-industry/types'

const MARGIN_DECIMALS = 1

const props = defineProps<{ checks: ClosingChecksData }>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const checks = computed(() => buildClosingChecks(props.checks))
</script>

<template>
  <ul class="space-y-2" data-testid="closing-checks">
    <li
      v-for="check in checks"
      :key="check.key"
      :data-testid="`closing-check-${check.key}`"
      :data-ok="check.ok"
      class="flex items-start gap-2 rounded-sm border px-3 py-2 text-sm"
      :class="check.ok ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-200' : 'border-amber-500/30 bg-amber-500/5 text-amber-200'"
    >
      <span aria-hidden="true" class="font-mono">{{ check.ok ? '✓' : '!' }}</span>
      <span class="min-w-0">
        <template v-if="check.key === 'pendingContributions'">
          {{ t('groupIndustry.closingChecks.pendingContributions', { count: check.count }, check.count) }}
        </template>
        <template v-else-if="check.key === 'unsoldProducts'">
          <template v-if="check.products.length === 0">{{ t('groupIndustry.closingChecks.nothingUnsold') }}</template>
          <template v-else>
            {{ t('groupIndustry.closingChecks.unsoldProducts') }}
            <span v-for="product in check.products" :key="product.typeId" class="block font-mono text-xs">
              {{ product.typeName }} × {{ formatNumber(product.quantity, 0) }}
            </span>
          </template>
        </template>
        <template v-else-if="check.key === 'finalMargin'">
          <template v-if="check.marginPercent === null">{{ t('groupIndustry.closingChecks.marginUnknown') }}</template>
          <template v-else>{{ t('groupIndustry.closingChecks.finalMargin', { margin: check.marginPercent.toFixed(MARGIN_DECIMALS) }) }}</template>
        </template>
        <template v-else>
          <template v-if="check.total === null || check.received === null">{{ t('groupIndustry.closingChecks.transfersUnavailable') }}</template>
          <template v-else>{{ t('groupIndustry.closingChecks.transfers', { received: check.received, total: check.total }) }}</template>
        </template>
      </span>
    </li>
  </ul>
</template>
