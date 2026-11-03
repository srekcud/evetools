<script setup lang="ts">
import type { OreValueRow } from '@/composables/useOreValueTable'

defineProps<{
  rows: OreValueRow[]
}>()
</script>

<template>
  <table>
    <tbody>
      <tr v-for="row in rows" :key="row.typeId" :data-type-id="row.typeId">
        <td data-column="raw"></td>
        <td data-column="compressed"></td>
        <td data-column="reprocessed"></td>
      </tr>
    </tbody>
  </table>
</template>
