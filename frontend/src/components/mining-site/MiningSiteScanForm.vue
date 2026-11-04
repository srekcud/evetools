<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import { useMiningSiteOreTypes } from '@/composables/useMiningSiteOreTypes'
import { useMiningSiteStore, type MiningSite, type MiningSiteScan, type MiningSiteScanLine } from '@/stores/miningSite'
import { isEverythingDepleted } from '@/views/MiningSite/miningSiteEstimate'

interface DraftLine {
  key: number
  typeId: string
  units: string
}

const MAX_LINES = 100
const WHOLE_NUMBER_PATTERN = /^\d+$/

const props = defineProps<{ site: MiningSite }>()
const emit = defineEmits<{ recorded: [scan: MiningSiteScan] }>()

const { t } = useI18n()
const { etaText } = useMiningSiteFormat()
const store = useMiningSiteStore()
const oreTypes = useMiningSiteOreTypes()

const lines = ref<DraftLine[]>([])
const submitting = ref(false)
const failure = ref<string | null>(null)
const addedMessage = ref<string | null>(null)
let nextKey = 0

const hasScans = computed(() => props.site.lastScanNumber !== null && props.site.lastScanNumber !== undefined)
const shownFailure = computed(() => failure.value ?? oreTypes.error.value)

function addLine(): void {
  if (lines.value.length >= MAX_LINES) return
  lines.value.push({ key: nextKey++, typeId: '', units: '' })
}

function removeLine(key: number): void {
  lines.value = lines.value.filter(line => line.key !== key)
}

function validationFailure(): string | null {
  if (lines.value.length === 0) return t('miningSite.scan.errors.noLines')
  if (lines.value.some(line => line.typeId === '')) return t('miningSite.scan.errors.typeRequired')
  if (lines.value.some(line => !WHOLE_NUMBER_PATTERN.test(line.units.trim()) || Number(line.units) <= 0)) return t('miningSite.scan.errors.unitsInvalid')
  const typeIds = lines.value.map(line => line.typeId)
  if (new Set(typeIds).size !== typeIds.length) return t('miningSite.scan.errors.duplicate')
  return null
}

function trendEtaAfter(scan: MiningSiteScan): string {
  return isEverythingDepleted(scan) ? t('miningSite.eta.depleted') : etaText(scan.trendEta)
}

async function send(scanLines: MiningSiteScanLine[]): Promise<void> {
  const trendEtaBefore = etaText(props.site.trendEta)
  submitting.value = true
  failure.value = null
  addedMessage.value = null
  try {
    const recorded = await store.recordScan(props.site.id, scanLines)
    addedMessage.value = t('miningSite.scan.added', { number: recorded.number, from: trendEtaBefore, to: trendEtaAfter(recorded) })
    lines.value = []
    emit('recorded', recorded)
  } catch (cause) {
    failure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    submitting.value = false
  }
}

async function submit(): Promise<void> {
  const refusal = validationFailure()
  failure.value = refusal
  addedMessage.value = null
  if (refusal !== null) return
  await send(lines.value.map(line => ({ typeId: Number(line.typeId), units: Number(line.units) })))
}

onMounted(oreTypes.load)
</script>

<template>
  <form class="eve-card space-y-4 p-5" data-testid="scan-form" @submit.prevent="submit">
    <h2 class="font-display text-lg font-semibold text-slate-100">{{ t('miningSite.scan.title') }}</h2>

    <ul v-if="lines.length > 0" class="space-y-2">
      <li v-for="line in lines" :key="line.key" class="flex items-center gap-2" data-testid="scan-line">
        <select
          v-model="line.typeId"
          name="typeId"
          :aria-label="t('miningSite.scan.type')"
          class="min-w-0 flex-1 rounded-md border border-slate-700 bg-slate-950 px-2 py-2 text-sm text-slate-100"
        >
          <option value="">{{ t('miningSite.scan.chooseType') }}</option>
          <option v-for="row in oreTypes.rows.value" :key="row.typeId" :value="String(row.typeId)">{{ row.typeName }}</option>
        </select>
        <input
          v-model="line.units"
          name="units"
          type="text"
          inputmode="numeric"
          autocomplete="off"
          :aria-label="t('miningSite.scan.units')"
          :placeholder="t('miningSite.scan.units')"
          class="w-28 rounded-md border border-slate-700 bg-slate-950 px-2 py-2 text-right font-mono text-sm text-slate-100"
        />
        <button
          type="button"
          class="rounded-md border border-slate-700 px-2 py-2 text-sm text-slate-300 hover:bg-slate-800"
          :aria-label="t('miningSite.scan.removeLine')"
          :title="t('miningSite.scan.removeLine')"
          @click="removeLine(line.key)"
        >×</button>
      </li>
    </ul>

    <div class="flex flex-wrap items-center gap-3">
      <button
        type="button"
        data-testid="scan-add-line"
        :disabled="lines.length >= MAX_LINES"
        class="rounded-md border border-cyan-500/40 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:opacity-50"
        @click="addLine"
      >{{ t('miningSite.scan.addLine') }}</button>
      <button
        v-if="hasScans"
        type="button"
        data-testid="scan-all-depleted"
        :disabled="submitting"
        class="rounded-md border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
        @click="send([])"
      >{{ t('miningSite.scan.allDepleted') }}</button>
      <span class="font-mono text-xs text-slate-400">{{ lines.length }} / {{ MAX_LINES }}</span>
    </div>

    <p class="text-xs text-slate-400">{{ t('miningSite.scan.reminder') }}</p>

    <p v-if="shownFailure !== null" class="text-sm text-red-400" role="alert" data-testid="scan-error">{{ shownFailure }}</p>
    <p v-if="addedMessage !== null" class="rounded-sm border border-cyan-500/30 bg-cyan-500/5 px-3 py-2 font-mono text-sm text-cyan-200" role="status" data-testid="scan-added">{{ addedMessage }}</p>

    <button
      type="submit"
      :disabled="submitting"
      class="flex items-center gap-2 rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
    >
      <LoadingSpinner v-if="submitting" size="sm" />
      {{ t('miningSite.scan.submit') }}
    </button>
  </form>
</template>
