<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useMiningCompression } from '@/composables/useMiningCompression'
import type { MiningCompression } from '@/stores/miningOperation'

const props = defineProps<{
  compression: MiningCompression | null | undefined
}>()

const { t } = useI18n()
const { banners } = useMiningCompression(() => props.compression)
</script>

<template>
  <div v-if="banners.length > 0">
    <p
      v-for="banner in banners"
      :key="banner"
      class="mb-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200"
      role="alert"
    >{{ banner }}</p>
    <p class="mb-3 text-xs text-slate-400">{{ t('miningOperation.boostFit.sharesUnchanged') }}</p>
  </div>
</template>
