<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'

type MarketStructure = { id: number; name: string }

type MarketSettings = {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: MarketStructure[]
}

const props = withDefaults(defineProps<{
  modelValue: number | null
  disabled?: boolean
  currentStructure?: MarketStructure | null
  preselectPreferred?: boolean
}>(), {
  disabled: false,
  currentStructure: null,
  preselectPreferred: true,
})

const emit = defineEmits<{
  'update:modelValue': [structureId: number]
  loaded: [structureIds: number[]]
}>()

const { t } = useI18n()
const selectId = useId()
const settings = ref<MarketSettings | null>(null)
const loadFailed = ref(false)

const structures = computed<MarketStructure[]>(() => {
  if (settings.value === null) return []
  const listed: MarketStructure[] = []
  const preferredId = settings.value.preferredMarketStructureId
  if (preferredId !== null) {
    listed.push({ id: preferredId, name: settings.value.preferredMarketStructureName ?? `Structure ${preferredId}` })
  }
  for (const structure of settings.value.marketStructures) {
    if (!listed.some(known => known.id === structure.id)) listed.push(structure)
  }
  if (props.currentStructure !== null && !listed.some(known => known.id === props.currentStructure?.id)) {
    listed.push(props.currentStructure)
  }
  return listed
})

const needsPlaceholder = computed(() => props.modelValue === null && !props.preselectPreferred)

function preselectThePreferredStructure(): void {
  const preferredId = settings.value?.preferredMarketStructureId ?? null
  if (props.modelValue === null && props.preselectPreferred && preferredId !== null) {
    emit('update:modelValue', preferredId)
  }
}

function pick(event: Event): void {
  emit('update:modelValue', Number((event.target as HTMLSelectElement).value))
}

function isMarketSettings(value: unknown): value is MarketSettings {
  return typeof value === 'object' && value !== null && Array.isArray((value as MarketSettings).marketStructures)
}

onMounted(async () => {
  try {
    const loaded: unknown = await apiRequest<MarketSettings>('/me/settings')
    if (!isMarketSettings(loaded)) throw new Error('Unexpected market settings')
    settings.value = loaded
  } catch {
    loadFailed.value = true
    return
  }
  emit('loaded', structures.value.map(structure => structure.id))
  preselectThePreferredStructure()
})

watch(() => props.modelValue, preselectThePreferredStructure)
</script>

<template>
  <div>
    <label :for="selectId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.sellStructure.label') }}</label>
    <p v-if="loadFailed" data-testid="sell-structure-load-failed" class="text-xs text-amber-400">{{ t('groupIndustry.sellStructure.loadFailed') }}</p>
    <p v-else-if="settings !== null && structures.length === 0" data-testid="sell-structure-none" class="text-xs text-amber-400">{{ t('groupIndustry.sellStructure.none') }}</p>
    <select
      v-else-if="settings !== null"
      :id="selectId"
      data-testid="sell-structure-select"
      :value="modelValue ?? ''"
      :disabled="disabled"
      class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none disabled:opacity-50"
      @change="pick"
    >
      <option v-if="needsPlaceholder" value="" disabled>{{ t('groupIndustry.sellStructure.notSet') }}</option>
      <option v-for="structure in structures" :key="structure.id" :value="structure.id">{{ structure.name }}</option>
    </select>
  </div>
</template>
