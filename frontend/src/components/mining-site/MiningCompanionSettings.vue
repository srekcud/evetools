<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import {
  COMPANION_OPACITY_MAX,
  COMPANION_OPACITY_MIN,
  COMPANION_TEXT_SCALES,
  type CompanionTextScaleKey,
} from '@/views/MiningSite/miningCompanion'
import { RESCAN_MINUTES_MAX, RESCAN_MINUTES_MIN } from '@/views/MiningSite/miningSiteEstimate'

const OPACITY_STEP = 5
const TEXT_SCALE_KEYS = Object.keys(COMPANION_TEXT_SCALES) as CompanionTextScaleKey[]

withDefaults(
  defineProps<{ opacity: number; textScale: CompanionTextScaleKey; rescanMinutes: number; notifications?: boolean; clipboardOnFocus?: boolean }>(),
  { notifications: false, clipboardOnFocus: false },
)
const emit = defineEmits<{ opacity: [opacity: number]; 'text-scale': [textScale: CompanionTextScaleKey]; rescan: [minutes: number]; notifications: [enabled: boolean]; clipboard: [enabled: boolean] }>()

const { t } = useI18n()

function numberOf(event: Event): number {
  return Number.parseInt((event.target as HTMLInputElement).value, 10)
}

function changeOpacity(event: Event): void {
  const typed = numberOf(event)
  if (!Number.isNaN(typed)) emit('opacity', typed)
}

function checkedOf(event: Event): boolean {
  return (event.target as HTMLInputElement).checked
}

function changeRescan(event: Event): void {
  const typed = numberOf(event)
  if (!Number.isNaN(typed)) emit('rescan', typed)
}
</script>

<template>
  <div class="space-y-2 rounded-sm border border-slate-700 bg-slate-900/80 p-2 text-xs text-slate-300" data-testid="companion-settings">
    <label class="flex items-center justify-between gap-2">
      <span>{{ t('miningCompanion.settings.opacity') }}</span>
      <input
        type="range"
        name="companionOpacity"
        :min="COMPANION_OPACITY_MIN"
        :max="COMPANION_OPACITY_MAX"
        :step="OPACITY_STEP"
        :value="opacity"
        class="w-28"
        @input="changeOpacity"
      />
      <span class="w-10 text-right text-slate-100">{{ opacity }} %</span>
    </label>
    <div class="flex items-center justify-between gap-2">
      <span>{{ t('miningCompanion.settings.textSize') }}</span>
      <span class="flex gap-1">
        <button
          v-for="scaleKey in TEXT_SCALE_KEYS"
          :key="scaleKey"
          type="button"
          :data-testid="`companion-text-${scaleKey}`"
          :aria-pressed="textScale === scaleKey"
          class="rounded-sm border px-2 py-0.5"
          :class="textScale === scaleKey ? 'border-cyan-400/60 bg-cyan-500/15 text-cyan-100' : 'border-slate-700 text-slate-400 hover:bg-slate-800'"
          @click="emit('text-scale', scaleKey)"
        >{{ t(`miningCompanion.settings.textSizes.${scaleKey}`) }}</button>
      </span>
    </div>
    <label class="flex items-center justify-between gap-2">
      <span>{{ t('miningCompanion.settings.rescan') }}</span>
      <span class="flex items-center gap-1">
        <input
          type="number"
          name="companionRescanMinutes"
          :min="RESCAN_MINUTES_MIN"
          :max="RESCAN_MINUTES_MAX"
          :value="rescanMinutes"
          class="w-14 rounded-md border border-slate-700 bg-slate-950 px-2 py-0.5 text-right text-slate-100"
          @change="changeRescan"
        />
        <span>{{ t('miningCompanion.settings.minutes') }}</span>
      </span>
    </label>
    <label class="flex items-center justify-between gap-2">
      <span>{{ t('miningCompanion.settings.notifications') }}</span>
      <input
        type="checkbox"
        name="companionNotifications"
        data-testid="companion-notifications-toggle"
        :checked="notifications"
        @change="emit('notifications', checkedOf($event))"
      />
    </label>
    <label class="flex items-center justify-between gap-2">
      <span>{{ t('miningCompanion.settings.clipboard') }}</span>
      <input
        type="checkbox"
        name="companionClipboardOnFocus"
        data-testid="companion-clipboard-toggle"
        :checked="clipboardOnFocus"
        @change="emit('clipboard', checkedOf($event))"
      />
    </label>
  </div>
</template>
