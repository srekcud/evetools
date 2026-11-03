<script setup lang="ts">
import type { Character } from '@/types'
import type { ReprocessingProfile, ReprocessingProfileInput } from '@/composables/useReprocessingProfile'

defineProps<{
  profile: ReprocessingProfile | null
  characters: Character[]
}>()

defineEmits<{
  save: [input: ReprocessingProfileInput]
  refresh: []
}>()
</script>

<template>
  <section data-reprocessing-profile-panel>
    <select data-field="character" />
    <select data-field="oreProcessingSkill" />
    <select data-field="location" />
    <select data-field="rigTier" />
    <select data-field="security" />
    <select data-field="implant" />
    <span data-reprocess-yield />
    <span data-skill-level="reprocessing" />
    <span data-skill-level="reprocessingEfficiency" />
    <span data-skill-level="oreProcessing" />
    <button type="button" data-action="save" />
    <button type="button" data-action="refresh" />
  </section>
</template>
