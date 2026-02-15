<script setup lang="ts">
import { useI18n } from 'vue-i18n'

interface LocationOption {
  name: string
  solarSystemName: string | null
}

interface CharacterOption {
  id: string
  name: string
  isMain: boolean
}

const props = defineProps<{
  viewMode: 'character' | 'corporation'
  characters: CharacterOption[]
  selectedCharacterId: string | null
  searchQuery: string
  solarSystems: string[]
  locations: LocationOption[]
  selectedSolarSystem: string
  selectedLocation: string
}>()

const emit = defineEmits<{
  'update:viewMode': [value: 'character' | 'corporation']
  'update:selectedCharacterId': [value: string | null]
  'update:searchQuery': [value: string]
  'update:selectedSolarSystem': [value: string]
  'update:selectedLocation': [value: string]
  'clear-filters': []
}>()

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-col gap-4 mb-6">
    <!-- Row 1: View mode, character selector, search -->
    <div class="flex flex-col sm:flex-row gap-4">
      <!-- View mode tabs -->
      <div class="flex bg-slate-900 rounded-lg p-1">
        <button
          @click="emit('update:viewMode', 'character')"
          :class="['px-4 py-2 rounded-md text-sm font-medium transition-colors', props.viewMode === 'character' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-slate-200']"
        >
          {{ t('assets.characterAssets') }}
        </button>
        <button
          @click="emit('update:viewMode', 'corporation')"
          :class="['px-4 py-2 rounded-md text-sm font-medium transition-colors', props.viewMode === 'corporation' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-slate-200']"
        >
          {{ t('assets.corporationAssets') }}
        </button>
      </div>

      <!-- Character selector -->
      <select
        v-if="props.viewMode === 'character'"
        :value="props.selectedCharacterId"
        @change="emit('update:selectedCharacterId', ($event.target as HTMLSelectElement).value)"
        class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
      >
        <option v-for="char in props.characters" :key="char.id" :value="char.id">
          {{ char.name }} {{ char.isMain ? '(Main)' : '' }}
        </option>
      </select>

      <!-- Search -->
      <div class="flex-1 relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input
          :value="props.searchQuery"
          @input="emit('update:searchQuery', ($event.target as HTMLInputElement).value)"
          type="text"
          :placeholder="t('assets.searchPlaceholder')"
          class="w-full bg-slate-900 border border-slate-700 rounded-lg pl-10 pr-4 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
        />
      </div>
    </div>

    <!-- Row 2: Location filters -->
    <div class="flex flex-col sm:flex-row gap-4">
      <!-- Solar System filter -->
      <div class="relative">
        <select
          :value="props.selectedSolarSystem"
          @change="emit('update:selectedSolarSystem', ($event.target as HTMLSelectElement).value)"
          class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 pr-8 text-sm focus:outline-hidden focus:border-cyan-500 min-w-[200px]"
        >
          <option value="">{{ t('assets.allSystems') }} ({{ props.solarSystems.length }})</option>
          <option v-for="system in props.solarSystems" :key="system" :value="system">
            {{ system }}
          </option>
        </select>
      </div>

      <!-- Location/Structure filter -->
      <div class="relative">
        <select
          :value="props.selectedLocation"
          @change="emit('update:selectedLocation', ($event.target as HTMLSelectElement).value)"
          class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 pr-8 text-sm focus:outline-hidden focus:border-cyan-500 min-w-[250px]"
        >
          <option value="">{{ t('assets.allLocations') }} ({{ props.locations.length }})</option>
          <option v-for="loc in props.locations" :key="loc.name" :value="loc.name">
            {{ loc.name }}
          </option>
        </select>
      </div>

      <!-- Clear filters button -->
      <button
        v-if="props.selectedSolarSystem || props.selectedLocation || props.searchQuery"
        @click="emit('clear-filters')"
        class="px-3 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-400 hover:text-slate-200 text-sm flex items-center gap-2"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        {{ t('assets.clearFilters') }}
      </button>
    </div>
  </div>
</template>
