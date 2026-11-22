<script setup lang="ts">
import { onMounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCharacterActiveProjects, type ActiveProjectCharacter } from '@/composables/useCharacterActiveProjects'
import type { GroupProject } from '@/stores/group-industry/types'

const props = defineProps<{ project: GroupProject }>()

const { t } = useI18n()
const groupId = useId()
const { characters, loadFailed, load, dedicate } = useCharacterActiveProjects()
const savingCharacterIds = ref<string[]>([])
const saveFailed = ref(false)

function isDedicatedHere(character: ActiveProjectCharacter): boolean {
  return character.activeProjectId === props.project.id
}

function otherProjectName(character: ActiveProjectCharacter): string | null {
  return character.activeProjectId !== null && !isDedicatedHere(character) ? character.activeProjectName : null
}

async function toggle(character: ActiveProjectCharacter, box: HTMLInputElement): Promise<void> {
  savingCharacterIds.value = [...savingCharacterIds.value, character.id]
  saveFailed.value = false
  try {
    await dedicate(character.id, box.checked ? props.project.id : null)
  } catch {
    saveFailed.value = true
    box.checked = isDedicatedHere(character)
  } finally {
    savingCharacterIds.value = savingCharacterIds.value.filter(id => id !== character.id)
  }
}

onMounted(load)
</script>

<template>
  <div
    v-if="project.status !== 'completed' && (loadFailed || characters.length > 0)"
    data-testid="project-dedicated-characters"
    :aria-labelledby="groupId"
    role="group"
  >
    <p :id="groupId" class="text-sm font-medium text-slate-300 mb-1">{{ t('industry.activeProject.panelTitle') }}</p>
    <p v-if="loadFailed" data-testid="project-dedicated-characters-load-failed" class="text-xs text-amber-400">{{ t('industry.activeProject.error') }}</p>
    <template v-else>
      <p class="text-xs text-slate-500 mb-2">{{ t('industry.activeProject.hint') }}</p>
      <div class="grid grid-cols-2 gap-x-6 gap-y-2">
        <label
          v-for="character in characters"
          :key="character.id"
          class="flex items-center gap-2.5"
          :class="savingCharacterIds.includes(character.id) ? 'cursor-not-allowed' : 'cursor-pointer'"
        >
          <input
            type="checkbox"
            :data-testid="`dedicate-character-${character.id}`"
            :checked="isDedicatedHere(character)"
            :disabled="savingCharacterIds.includes(character.id)"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 disabled:opacity-50"
            @change="toggle(character, $event.target as HTMLInputElement)"
          />
          <span class="text-sm text-slate-300">{{ character.name }}</span>
          <span
            v-if="otherProjectName(character) !== null"
            :data-testid="`dedicate-character-other-${character.id}`"
            class="text-xs text-slate-500"
          >{{ t('industry.activeProject.currentlyDedicatedTo', { project: otherProjectName(character) }) }}</span>
        </label>
      </div>
      <p v-if="saveFailed" data-testid="project-dedicated-characters-save-failed" class="text-xs text-amber-400 mt-2">{{ t('industry.activeProject.error') }}</p>
    </template>
  </div>
</template>
