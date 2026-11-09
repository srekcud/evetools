<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { usePilotName } from '@/composables/usePilotName'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import { buildProjectDiscordRecap } from '@/components/group-industry/projectDiscordRecap'

const DISCORD_MESSAGE_LIMIT = 2000
const COPIED_FEEDBACK_MILLISECONDS = 2000

const { t } = useI18n()
const { formatIsk, formatIskExact } = useFormatters()
const { pilotName } = usePilotName()
const projectStore = useGroupProjectStore()
const distributionStore = useGroupDistributionStore()
const settlementStore = useGroupSettlementStore()

const recap = computed((): string | null => {
  const project = projectStore.currentProject
  const distribution = distributionStore.distribution
  if (project === null || project.status !== 'completed' || distribution === null) return null
  return buildProjectDiscordRecap({
    project,
    distribution,
    settlement: settlementStore.settlement,
    t,
    formatIsk: value => formatIsk(value),
    formatExactIsk: formatIskExact,
    pilotName,
  })
})

const overLimit = computed(() => recap.value !== null && recap.value.length > DISCORD_MESSAGE_LIMIT)

const copied = ref(false)
const copyFailed = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | undefined

async function copyRecap(): Promise<void> {
  if (recap.value === null) return
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(recap.value)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MILLISECONDS)
  } catch {
    copied.value = false
    copyFailed.value = true
  }
}

onBeforeUnmount(() => clearTimeout(copiedTimer))
</script>

<template>
  <section v-if="recap !== null" class="bg-slate-900/80 rounded-xl border border-slate-800 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('groupIndustry.discordRecap.title') }}</h2>
      <button
        type="button"
        data-test="copy-discord-recap"
        class="rounded-md border border-cyan-500/40 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/10"
        @click="copyRecap"
      >{{ copied ? t('groupIndustry.discordRecap.copied') : t('groupIndustry.discordRecap.copy') }}</button>
    </div>
    <p class="mb-3 text-xs text-slate-400">{{ t('groupIndustry.discordRecap.hint') }}</p>
    <pre data-test="discord-recap-text" class="whitespace-pre-wrap break-words rounded-sm border border-slate-700 bg-slate-950/60 p-3 font-mono text-xs text-slate-200">{{ recap }}</pre>
    <p
      data-test="discord-recap-length"
      :data-over-limit="overLimit"
      class="mt-2 font-mono text-xs"
      :class="overLimit ? 'text-red-400' : 'text-slate-400'"
    >{{ recap.length }} / {{ DISCORD_MESSAGE_LIMIT }}</p>
    <p v-if="overLimit" class="mt-1 text-xs text-red-400">{{ t('groupIndustry.discordRecap.tooLong') }}</p>
    <p v-if="copyFailed" data-test="discord-recap-copy-failed" class="mt-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200" role="alert">{{ t('groupIndustry.discordRecap.copyFailed') }}</p>
  </section>
</template>
