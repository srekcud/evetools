<script setup lang="ts">
withDefaults(defineProps<{
  stickyHeader?: boolean
}>(), {
  stickyHeader: true,
})
</script>

<template>
  <div
    class="overflow-x-auto"
    :class="{ 'data-table-sticky-header': stickyHeader }"
  >
    <slot />
  </div>
</template>
