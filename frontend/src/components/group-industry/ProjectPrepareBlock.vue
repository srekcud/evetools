<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  status: string
}>()

const { t } = useI18n()

const isDraft = computed(() => props.status === 'draft')
</script>

<template>
  <section
    v-if="isDraft"
    data-testid="prepare-block"
    class="mb-6 rounded-xl border border-cyan-500/30 bg-slate-950/40 p-4"
  >
    <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
      {{ t('groupIndustry.prepare.title') }}
    </h2>
    <p class="mb-4 mt-1 text-xs text-slate-500">{{ t('groupIndustry.prepare.help') }}</p>
    <slot />
  </section>
  <slot v-else />
</template>
