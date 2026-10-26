<script setup lang="ts">
defineProps<{
  show: boolean
  title: string
}>()

defineEmits<{
  close: []
}>()
</script>

<template>
  <div v-if="show">
    <h2>{{ title }}</h2>
    <slot />
  </div>
</template>
