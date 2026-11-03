<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  name: string | null
  id: number | null
}>()

const { t } = useI18n()
</script>

<template>
  <span v-if="name !== null" class="break-words">{{ name }}</span>
  <span v-else-if="id !== null" class="break-words">
    {{ id }}
    <span data-unknown-name class="text-xs uppercase tracking-wider text-amber-500">
      <span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonChunks.unknownName') }}
    </span>
  </span>
  <span v-else :title="t('common.unknownValue')">—</span>
</template>
