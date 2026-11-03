<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningFleetStorageUnknownReason, MiningFleetStorageVerification, MiningFleetStorageVerificationLine } from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const REASONS_SOLVED_BY_A_REFRESH: MiningFleetStorageUnknownReason[] = ['corporation_assets_not_synced', 'assets_synced_before_end', 'container_not_found']

const props = defineProps<{
  verification: MiningFleetStorageVerification
  endedAt: string | null
  unknownQuantityNames: string
  refreshing: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

const { t, locale } = useI18n()

const nothingToCheck = computed(() => props.verification.status === 'nothing_delivered')
const needsRefresh = computed(() => {
  const reason = props.verification.unknownReason
  return props.verification.status === 'short' || (reason !== null && REASONS_SOLVED_BY_A_REFRESH.includes(reason))
})
const showsNoEffect = computed(() => props.verification.status === 'verified' || props.verification.status === 'short')
const readAtText = computed(() => (props.verification.assetsReadAt === null ? null : formatEveClock(props.verification.assetsReadAt)))
const reasonText = computed(() => {
  const reason = props.verification.unknownReason
  if (reason === null) return null
  return t(`miningOperation.fleetStorage.verification.reason.${reason}`, {
    time: readAtText.value ?? '',
    end: props.endedAt === null ? '' : formatEveClock(props.endedAt),
    name: props.verification.containerName ?? '',
    names: props.unknownQuantityNames,
  })
})

function foundText(line: MiningFleetStorageVerificationLine): string {
  return t('miningOperation.fleetStorage.verification.line', {
    found: line.foundQuantity === null ? '—' : formatWhole(line.foundQuantity, locale.value),
    expected: formatWhole(line.expectedQuantity, locale.value),
  })
}

function statusText(line: MiningFleetStorageVerificationLine): string {
  return t(`miningOperation.fleetStorage.verification.status.${line.status}`, { missing: line.missingQuantity === null ? '' : formatWhole(line.missingQuantity, locale.value) })
}

function badgeClass(status: MiningFleetStorageVerificationLine['status']): string {
  return status === 'verified' ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-300'
}
</script>

<template>
  <div class="mt-4 border-t border-slate-800 pt-4">
    <p v-if="nothingToCheck" class="text-xs text-slate-400">{{ t('miningOperation.fleetStorage.verification.status.nothing_delivered') }}</p>
    <template v-else>
      <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 v-if="verification.containerName !== null" class="min-w-0 text-sm font-semibold text-slate-200">
          <i18n-t keypath="miningOperation.fleetStorage.verification.title" scope="global" tag="span">
            <template #name><span class="inline-block max-w-48 truncate align-bottom" :title="verification.containerName">{{ verification.containerName }}</span></template>
          </i18n-t>
        </h3>
        <p v-if="readAtText !== null" class="font-mono text-xs text-slate-400">{{ t('miningOperation.fleetStorage.verification.assetsReadAt', { time: readAtText }) }}</p>
      </div>

      <ul v-if="verification.lines.length > 0" class="mt-2 divide-y divide-slate-800/60">
        <li v-for="line in verification.lines" :key="line.typeId" class="grid grid-cols-[1fr_auto_auto] items-baseline gap-x-3 py-1.5 text-sm">
          <span class="min-w-0 truncate text-slate-200" :title="line.typeName ?? String(line.typeId)">{{ line.typeName ?? line.typeId }}</span>
          <span class="font-mono text-xs" :class="line.foundQuantity === null ? 'text-amber-300' : 'text-slate-100'">{{ foundText(line) }}</span>
          <span class="rounded-sm border px-1.5 py-0.5 font-mono text-xs" :class="badgeClass(line.status)">{{ statusText(line) }}</span>
        </li>
      </ul>

      <p v-if="reasonText !== null" class="mt-2 text-xs text-amber-300">{{ reasonText }}</p>
      <p v-if="showsNoEffect" class="mt-2 text-xs text-slate-500">{{ t('miningOperation.fleetStorage.verification.noEffect') }}</p>

      <button
        v-if="needsRefresh"
        type="button"
        class="mt-3 rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="refreshing"
        @click="emit('refresh')"
      >{{ t('miningOperation.fleetStorage.verification.refresh') }}</button>
    </template>
  </div>
</template>
