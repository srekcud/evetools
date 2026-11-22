<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useCharacterActiveProjects, type CharacterActiveProjectUpdate } from '@/composables/useCharacterActiveProjects'

const props = defineProps<{
  character: { id: string; name: string; activeProjectId: string | null; activeProjectName: string | null }
}>()

const emit = defineEmits<{
  updated: [update: CharacterActiveProjectUpdate]
}>()

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const { dedicate } = useCharacterActiveProjects()

const NO_PROJECT = ''

const selectedProjectId = ref(props.character.activeProjectId ?? NO_PROJECT)
const saving = ref(false)
const failed = ref(false)

const openProjects = computed(() => projectStore.myProjects.filter(project => project.status !== 'completed'))

const unlistedActiveProject = computed(() => {
  const { activeProjectId, activeProjectName } = props.character
  if (activeProjectId === null || activeProjectName === null) return null
  return openProjects.value.some(project => project.id === activeProjectId) ? null : { id: activeProjectId, name: activeProjectName }
})

watch(() => props.character.activeProjectId, activeProjectId => {
  selectedProjectId.value = activeProjectId ?? NO_PROJECT
})

async function choose(projectId: string): Promise<void> {
  saving.value = true
  failed.value = false
  try {
    const updated = await dedicate(props.character.id, projectId === NO_PROJECT ? null : projectId)
    selectedProjectId.value = updated.activeProjectId ?? NO_PROJECT
    emit('updated', updated)
  } catch {
    failed.value = true
    selectedProjectId.value = props.character.activeProjectId ?? NO_PROJECT
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="inline-flex flex-col items-center gap-1">
    <select
      data-testid="character-active-project-select"
      :value="selectedProjectId"
      :disabled="saving"
      :aria-label="t('industry.activeProject.placeholder', { character: character.name })"
      class="bg-slate-800 border border-slate-700 rounded-sm px-2 py-1 text-sm text-cyan-400 focus:outline-hidden focus:border-cyan-500 disabled:opacity-50 max-w-48"
      @change="choose(($event.target as HTMLSelectElement).value)"
    >
      <option :value="NO_PROJECT">{{ t('industry.activeProject.none') }}</option>
      <option v-if="unlistedActiveProject !== null" :value="unlistedActiveProject.id">{{ unlistedActiveProject.name }}</option>
      <option v-for="project in openProjects" :key="project.id" :value="project.id">{{ project.name }}</option>
    </select>
    <p v-if="failed" data-testid="character-active-project-error" class="text-xs text-amber-400">{{ t('industry.activeProject.error') }}</p>
  </div>
</template>
