<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { AdminSdeBuild } from '@/stores/admin'

const props = defineProps<{
  build: AdminSdeBuild
}>()

const { t, locale } = useI18n()
const { formatTimeSince } = useFormatters()

const MISSING_VALUE = '--'
const RELATIVE_TIME_TICK_MS = 30000
const IMPORT_COMMAND = 'make sde-import'

const ticked = ref<Date | null>(null)
let tickTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  tickTimer = setInterval(() => {
    ticked.value = new Date()
  }, RELATIVE_TIME_TICK_MS)
})

onUnmounted(() => {
  if (tickTimer !== null) clearInterval(tickTimer)
})

const statusClasses: Record<AdminSdeBuild['status'], string> = {
  unknown: 'bg-slate-500/20 text-slate-400',
  not_recorded: 'bg-amber-500/20 text-amber-400',
  up_to_date: 'bg-emerald-500/20 text-emerald-400',
  outdated: 'bg-amber-500/20 text-amber-400',
}

const needsImport = computed(() => props.build.status === 'outdated' || props.build.status === 'not_recorded')

function buildNumber(value: number | null): string {
  return value === null ? MISSING_VALUE : String(value)
}

function releaseDate(value: string | null): string {
  if (value === null) return MISSING_VALUE
  return new Date(value).toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
}

const checkedAt = computed(() =>
  props.build.latestCheckedAt === null ? MISSING_VALUE : formatTimeSince(props.build.latestCheckedAt, ticked.value ?? undefined),
)
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.sdeBuild.title') }}</h3>
      <span
        data-testid="sde-build-status"
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', statusClasses[build.status]]"
      >{{ t(`admin.sdeBuild.status.${build.status}`) }}</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.sdeBuild.imported') }}</p>
        <p data-testid="sde-build-imported-number" class="text-2xl font-mono text-slate-100">{{ buildNumber(build.importedBuildNumber) }}</p>
        <p data-testid="sde-build-imported-date" class="text-sm font-mono text-slate-400">{{ releaseDate(build.importedReleaseDate) }}</p>
      </div>
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.sdeBuild.latest') }}</p>
        <p data-testid="sde-build-latest-number" class="text-2xl font-mono text-slate-100">{{ buildNumber(build.latestBuildNumber) }}</p>
        <p data-testid="sde-build-latest-date" class="text-sm font-mono text-slate-400">{{ releaseDate(build.latestReleaseDate) }}</p>
      </div>
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.sdeBuild.lastCheck') }}</p>
        <p data-testid="sde-build-checked-at" class="text-sm font-mono text-slate-300">{{ checkedAt }}</p>
      </div>
    </div>

    <div v-if="needsImport" class="mt-4">
      <p class="text-xs text-slate-500 mb-1">{{ t('admin.sdeBuild.runCommand') }}</p>
      <code data-testid="sde-build-command" class="block rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-sm font-mono text-amber-400">{{ IMPORT_COMMAND }}</code>
    </div>
  </div>
</template>
