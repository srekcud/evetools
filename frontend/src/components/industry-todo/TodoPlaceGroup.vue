<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import type { PlacedAction, PlacedActionCode } from '@/stores/group-industry/types'
import { multibuyText } from '@/utils/multibuy'
import { placeMultibuy, type PlaceGroup } from '@/utils/todoPlaces'

const COPIED_FEEDBACK_MS = 2000
const KNOWN_UNKNOWN_REASONS = ['market_not_chosen', 'market_structure_unset', 'station_unresolved']
const PLURAL_DETAIL_CODES: PlacedActionCode[] = ['launch_job', 'review_contributions', 'review_join_requests']

const props = defineProps<{
  group: PlaceGroup
}>()

const { t, n } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()

const copied = ref(false)
const copyFailed = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

function isKnownReason(reason: string | null): reason is string {
  return reason !== null && KNOWN_UNKNOWN_REASONS.includes(reason)
}

const multibuy = computed(() => placeMultibuy(props.group))

const placeTitle = computed(() => {
  const { place } = props.group
  if (place.kind === 'station') {
    return place.name === null ? t('industryTodo.unnamedStation') : place.name
  }
  if (place.kind === 'anywhere') {
    return t('industryTodo.anywhere')
  }
  return isKnownReason(place.unknownReason) ? t(`industryTodo.unknownReasons.${place.unknownReason}`) : t('industryTodo.unknownPlace')
})

const placeSubtitle = computed(() => {
  const { place } = props.group
  if (place.kind === 'station') {
    return place.solarSystemName
  }
  if (place.kind === 'unknown' && !isKnownReason(place.unknownReason)) {
    return place.unknownReason
  }
  return null
})

const multibuyLabel = computed(() => {
  const read = multibuy.value
  if (read === null) {
    return ''
  }
  return t('industryTodo.multibuy.button', {
    lines: n(read.lineCount),
    projects: t('industryTodo.multibuy.projects', read.projectCount),
  })
})

function detailOf(action: PlacedAction): string {
  if (action.quantity === null) {
    return action.code === 'launch_job' ? t('industryTodo.details.launchJobUnknownRuns') : ''
  }
  const key = `industryTodo.details.${action.code}`
  return PLURAL_DETAIL_CODES.includes(action.code) ? t(key, { n: n(action.quantity) }, action.quantity) : t(key, { n: n(action.quantity) })
}

function projectLabel(action: PlacedAction): string {
  return action.projectName === null || action.projectName === '' ? t('industryTodo.unnamedProject') : action.projectName
}

async function copyMultibuy(): Promise<void> {
  const read = multibuy.value
  if (read === null) {
    return
  }
  if (copiedTimer !== null) {
    clearTimeout(copiedTimer)
    copiedTimer = null
  }
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(multibuyText(read.lines))
  } catch {
    copied.value = false
    copyFailed.value = true
    return
  }
  copyFailed.value = false
  copied.value = true
  copiedTimer = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MS)
}

onBeforeUnmount(() => {
  if (copiedTimer !== null) {
    clearTimeout(copiedTimer)
  }
})
</script>

<template>
  <section class="border-b border-slate-800 last:border-b-0" data-testid="todo-place-group" :data-place-key="group.key">
    <header class="flex items-center gap-2 px-3 pb-1 pt-2" data-testid="todo-place-header">
      <span class="min-w-0 truncate font-display text-sm font-semibold tracking-wide text-cyan-300" :title="placeTitle">{{ placeTitle }}</span>
      <span v-if="placeSubtitle" class="shrink-0 text-xs text-slate-500">{{ placeSubtitle }}</span>
      <span
        v-if="group.here === true"
        data-testid="todo-here-badge"
        class="shrink-0 whitespace-nowrap rounded-sm border border-emerald-400/30 px-1.5 text-[10.5px] text-emerald-400"
      >{{ t('industryTodo.hereBadge') }}</span>
    </header>
    <div v-if="multibuy" class="px-3 pb-2">
      <button
        type="button"
        data-testid="todo-multibuy"
        class="rounded border border-cyan-500/40 bg-cyan-500/10 px-2.5 py-1 text-xs font-medium text-cyan-300 transition-colors hover:bg-cyan-500/20"
        @click="copyMultibuy"
      >{{ multibuyLabel }}</button>
      <p v-if="copied" data-testid="todo-multibuy-feedback" class="mt-1 text-xs text-emerald-400">{{ t('industryTodo.multibuy.copied') }}</p>
      <p v-if="copyFailed" data-testid="todo-copy-error" class="mt-1 text-xs text-red-400">{{ t('industryTodo.multibuy.copyFailed') }}</p>
      <p v-if="multibuy.skippedCount > 0" data-testid="todo-multibuy-skipped" class="mt-1 text-xs text-slate-500">{{ t('industryTodo.multibuy.skipped', multibuy.skippedCount) }}</p>
    </div>
    <router-link
      v-for="action in group.actions"
      :key="action.id"
      :to="{ name: 'industry-project', params: { id: action.projectId } }"
      data-testid="todo-action"
      class="flex items-center gap-2 px-3 py-1.5 transition-colors hover:bg-slate-800/60"
    >
      <span class="w-16 shrink-0 font-display text-xs font-semibold uppercase tracking-wider text-amber-400">{{ t(`industryTodo.verbs.${action.code}`) }}</span>{{ ' ' }}
      <img
        v-if="action.typeId !== null"
        :src="getTypeIconUrl(action.typeId, 32)"
        alt=""
        class="h-8 w-8 shrink-0"
        @error="onImageError"
      >{{ ' ' }}
      <span class="min-w-0 flex-1">
        <span v-if="action.typeName" class="block truncate text-sm text-slate-200" :title="action.typeName">{{ action.typeName }}</span>{{ ' ' }}
        <small v-if="detailOf(action)" class="block text-xs text-slate-400">{{ detailOf(action) }}</small>{{ ' ' }}
        <span class="mt-0.5 inline-block rounded-sm border border-slate-700 px-1 text-[10.5px] text-slate-400">{{ projectLabel(action) }}</span>{{ ' ' }}
        <span
          v-if="action.access === 'unknown'"
          data-testid="todo-access-unknown"
          class="mt-0.5 inline-block rounded-sm border border-amber-400/30 px-1 text-[10.5px] text-amber-400"
        >{{ t('industryTodo.accessUnknown') }}</span>
      </span>
      <svg class="h-4 w-4 shrink-0 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
      </svg>
    </router-link>
  </section>
</template>
