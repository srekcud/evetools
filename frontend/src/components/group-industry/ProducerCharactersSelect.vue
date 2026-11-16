<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'

type ProducerCharacter = { eveCharacterId: number; name: string }

const props = withDefaults(defineProps<{
  modelValue: number[] | null
  disabled?: boolean
}>(), {
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [eveCharacterIds: number[]]
}>()

const { t } = useI18n()
const groupId = useId()
const characters = ref<ProducerCharacter[]>([])
const loadFailed = ref(false)

const tickedIds = computed<number[]>(() =>
  characters.value
    .map(character => character.eveCharacterId)
    .filter(eveCharacterId => props.modelValue === null || props.modelValue.includes(eveCharacterId)),
)

function isTicked(character: ProducerCharacter): boolean {
  return tickedIds.value.includes(character.eveCharacterId)
}

function isLockedOn(character: ProducerCharacter): boolean {
  return isTicked(character) && tickedIds.value.length <= 1
}

function toggle(character: ProducerCharacter, ticked: boolean): void {
  emit('update:modelValue', characters.value
    .map(candidate => candidate.eveCharacterId)
    .filter(eveCharacterId => eveCharacterId === character.eveCharacterId ? ticked : tickedIds.value.includes(eveCharacterId)))
}

function isCharacterList(value: unknown): value is ProducerCharacter[] {
  return Array.isArray(value) && value.every(candidate =>
    typeof candidate === 'object' && candidate !== null
    && typeof (candidate as ProducerCharacter).eveCharacterId === 'number'
    && typeof (candidate as ProducerCharacter).name === 'string')
}

onMounted(async () => {
  try {
    const loaded: unknown = await apiRequest<ProducerCharacter[]>('/me/characters')
    if (!isCharacterList(loaded)) throw new Error('Unexpected characters')
    characters.value = loaded
  } catch {
    loadFailed.value = true
  }
})
</script>

<template>
  <div v-if="loadFailed || characters.length > 0" :aria-labelledby="groupId" role="group">
    <p :id="groupId" class="text-sm font-medium text-slate-300 mb-1">{{ t('groupIndustry.producerCharacters.label') }}</p>
    <p v-if="loadFailed" data-testid="producer-characters-load-failed" class="text-xs text-amber-400">{{ t('groupIndustry.producerCharacters.loadFailed') }}</p>
    <template v-else>
      <p class="text-xs text-slate-500 mb-2">{{ t('groupIndustry.producerCharacters.hint') }}</p>
      <div class="grid grid-cols-2 gap-x-6 gap-y-2">
        <label
          v-for="character in characters"
          :key="character.eveCharacterId"
          class="flex items-center gap-2.5"
          :class="disabled || isLockedOn(character) ? 'cursor-not-allowed' : 'cursor-pointer'"
        >
          <input
            type="checkbox"
            :data-testid="`producer-character-${character.eveCharacterId}`"
            :checked="isTicked(character)"
            :disabled="disabled || isLockedOn(character)"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 disabled:opacity-50"
            @change="toggle(character, ($event.target as HTMLInputElement).checked)"
          />
          <span class="text-sm text-slate-300">{{ character.name }}</span>
        </label>
      </div>
    </template>
  </div>
</template>
