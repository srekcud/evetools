<script setup lang="ts">
withDefaults(defineProps<{
  stickyHeader?: boolean
}>(), {
  stickyHeader: true,
})
</script>

<template>
  <slot />
</template>
