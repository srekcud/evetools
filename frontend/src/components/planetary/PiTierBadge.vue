<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'

const props = defineProps<{
  tier: string | null | undefined
}>()

const { t } = useI18n()

const config = computed(() => (props.tier ? TIER_CONFIG[props.tier] ?? null : null))
</script>

<template>
  <span
    v-if="config"
    data-testid="tier-badge"
    :title="t(config.labelKey)"
    :class="['text-xs px-1.5 py-0.5 rounded-full font-medium uppercase tracking-wider border whitespace-nowrap', config.badgeBg, config.badgeText, config.badgeBorder]"
  >{{ tier }}</span>
</template>
