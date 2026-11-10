<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MiningCompanionNextSite from '@/components/mining-site/MiningCompanionNextSite.vue'
import { chunkCountdown, moonChunkStatusAt, type CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { UNKNOWN_DASH } from '@/composables/useMiningSiteFormat'
import type { CompanionSizeKey } from '@/views/MiningSite/miningCompanion'

const props = defineProps<{
  chunk: CorporationMiningExtraction | null
  now: Date
  loading: boolean
  failure: string | null
  estimating: boolean
  estimateFailure: string | null
  size?: CompanionSizeKey
}>()
const emit = defineEmits<{ 'create-operation': [chunkId: string]; estimate: [input: { label: string; solarSystemId: number | null }] }>()

const { t } = useI18n()

const estimateOpen = ref(false)

const showsActions = computed(() => props.size !== 'bar')
const moonText = computed(() => (props.chunk === null ? '' : (props.chunk.moonName ?? String(props.chunk.moonId))))
const systemText = computed(() => props.chunk?.solarSystemName ?? UNKNOWN_DASH)
const statusText = computed(() => (props.chunk === null ? '' : t(`ledger.mining.moonChunks.status.${moonChunkStatusAt(props.chunk, props.now)}`)))
const countdownText = computed(() => (props.chunk === null ? null : (chunkCountdown(props.chunk, props.now) ?? UNKNOWN_DASH)))

function submitEstimate(input: { label: string; solarSystemId: number | null }): void {
  emit('estimate', input)
}
</script>

<template>
  <section class="space-y-2" data-testid="companion-free">
    <p v-if="loading" class="text-sm text-slate-300" role="status">{{ t('miningCompanion.loading') }}</p>
    <p v-else-if="failure !== null" class="rounded-sm border border-red-500/40 px-2 py-2 text-sm text-red-400" role="alert" data-testid="companion-free-error">{{ failure }}</p>
    <div v-else-if="chunk !== null" class="rounded-sm border border-slate-700 px-2 py-2" data-testid="companion-free-chunk">
      <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('miningCompanion.free.next') }}</p>
      <p class="truncate font-display text-sm font-semibold text-cyan-200" :title="moonText">{{ moonText }}</p>
      <p v-if="showsActions" class="text-xs text-slate-300">
        <span>{{ systemText }}</span>
        <span class="ml-2 rounded-sm border border-slate-600 px-1.5 py-0.5 uppercase">{{ statusText }}</span>
      </p>
      <p class="text-2xl leading-none text-slate-100" data-testid="companion-free-countdown">{{ countdownText }}</p>
    </div>
    <p v-else class="rounded-sm border border-slate-700 px-2 py-3 text-sm text-slate-300" data-testid="companion-free-none">{{ t('miningCompanion.free.none') }}</p>

    <div v-if="showsActions" class="flex flex-wrap items-center gap-2">
      <button
        v-if="chunk !== null && !loading && failure === null"
        type="button"
        data-testid="companion-free-create-operation"
        class="rounded-sm border border-cyan-500/40 px-2 py-1 text-xs text-cyan-200 hover:bg-cyan-500/10"
        @click="emit('create-operation', chunk.id)"
      >{{ t('miningCompanion.free.createOperation') }}</button>
      <button
        type="button"
        data-testid="companion-free-estimate"
        class="rounded-sm border border-slate-600 px-2 py-1 text-xs text-slate-200 hover:bg-slate-800"
        @click="estimateOpen = !estimateOpen"
      >{{ t('miningCompanion.free.estimate') }}</button>
    </div>

    <MiningCompanionNextSite
      v-if="showsActions && estimateOpen"
      :failure="estimateFailure"
      :submitting="estimating"
      :closes-active="false"
      @submit="submitEstimate"
      @cancel="estimateOpen = false"
    />
  </section>
</template>
