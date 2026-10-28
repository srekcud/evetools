<script setup lang="ts">
defineProps<{
  show: boolean
  isAdmin: boolean
}>()

defineEmits<{
  close: []
}>()
</script>

<template>
  <div v-if="show"></div>
</template>
