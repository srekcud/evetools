<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useStructuresStore } from '@/stores/industry/structures'
import { apiRequest } from '@/services/api'

const store = useStructuresStore()

interface SolarSystemResult {
  solarSystemId: number
  solarSystemName: string
  security: number
  regionName: string
}

const mfgQuery = ref('')
const mfgResults = ref<SolarSystemResult[]>([])
const mfgDropdown = ref(false)
let mfgTimeout: ReturnType<typeof setTimeout> | null = null

const rxnQuery = ref('')
const rxnResults = ref<SolarSystemResult[]>([])
const rxnDropdown = ref(false)
let rxnTimeout: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  store.fetchUserSettings()
})

function securityColor(sec: number): string {
  if (sec >= 0.5) return 'text-emerald-400'
  if (sec > 0.0) return 'text-amber-400'
  return 'text-red-400'
}

async function searchSystems(query: string, target: 'mfg' | 'rxn') {
  if (query.length < 2) {
    if (target === 'mfg') mfgResults.value = []
    else rxnResults.value = []
    return
  }
  try {
    const data = await apiRequest<SolarSystemResult[]>(
      `/sde/solar-systems?q=${encodeURIComponent(query)}`,
    )
    if (target === 'mfg') {
      mfgResults.value = data
      mfgDropdown.value = true
    } else {
      rxnResults.value = data
      rxnDropdown.value = true
    }
  } catch (e) {
    // silent
  }
}

watch(mfgQuery, (q) => {
  if (mfgTimeout) clearTimeout(mfgTimeout)
  if (q.length < 2) { mfgResults.value = []; return }
  mfgTimeout = setTimeout(() => searchSystems(q, 'mfg'), 300)
})

watch(rxnQuery, (q) => {
  if (rxnTimeout) clearTimeout(rxnTimeout)
  if (q.length < 2) { rxnResults.value = []; return }
  rxnTimeout = setTimeout(() => searchSystems(q, 'rxn'), 300)
})

async function selectMfgSystem(system: SolarSystemResult) {
  mfgQuery.value = ''
  mfgResults.value = []
  mfgDropdown.value = false
  await store.updateUserSettings({
    favoriteManufacturingSystemId: system.solarSystemId,
  } as any)
}

async function selectRxnSystem(system: SolarSystemResult) {
  rxnQuery.value = ''
  rxnResults.value = []
  rxnDropdown.value = false
  await store.updateUserSettings({
    favoriteReactionSystemId: system.solarSystemId,
  } as any)
}

async function clearMfgSystem() {
  await store.updateUserSettings({
    favoriteManufacturingSystemId: 0,
  } as any)
}

async function clearRxnSystem() {
  await store.updateUserSettings({
    favoriteReactionSystemId: 0,
  } as any)
}

function onBlur(target: 'mfg' | 'rxn') {
  setTimeout(() => {
    if (target === 'mfg') mfgDropdown.value = false
    else rxnDropdown.value = false
  }, 200)
}
</script>

<template>
  <div class="mb-6 p-4 bg-slate-800/50 rounded-lg border border-slate-700">
    <h4 class="text-sm font-medium text-slate-300 mb-3">Systèmes favoris</h4>
    <p class="text-xs text-slate-500 mb-4">
      La meilleure structure dans le système favori sera automatiquement suggérée pour chaque step.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Manufacturing favorite -->
      <div>
        <label class="block text-xs text-slate-400 mb-1">Système favori manufacture</label>
        <div class="relative">
          <div v-if="store.userSettings?.favoriteManufacturingSystemId" class="flex items-center gap-2 bg-slate-700 rounded-lg px-3 py-2">
            <span class="text-sm text-slate-200 flex-1">
              {{ store.userSettings.favoriteManufacturingSystemName || `#${store.userSettings.favoriteManufacturingSystemId}` }}
            </span>
            <button @click="clearMfgSystem" class="text-slate-400 hover:text-red-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div v-else>
            <input
              v-model="mfgQuery"
              type="text"
              placeholder="Rechercher un système..."
              class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-cyan-500 placeholder-slate-500"
              @blur="onBlur('mfg')"
            />
            <div v-if="mfgDropdown && mfgResults.length > 0" class="absolute z-20 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-48 overflow-y-auto">
              <button
                v-for="sys in mfgResults"
                :key="sys.solarSystemId"
                @mousedown.prevent="selectMfgSystem(sys)"
                class="w-full px-3 py-2 text-left hover:bg-slate-700 text-sm flex items-center justify-between"
              >
                <span class="text-slate-200">{{ sys.solarSystemName }}</span>
                <span class="text-xs flex items-center gap-2">
                  <span :class="securityColor(sys.security)">{{ sys.security.toFixed(1) }}</span>
                  <span class="text-slate-500">{{ sys.regionName }}</span>
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Reaction favorite -->
      <div>
        <label class="block text-xs text-slate-400 mb-1">Système favori réactions</label>
        <div class="relative">
          <div v-if="store.userSettings?.favoriteReactionSystemId" class="flex items-center gap-2 bg-slate-700 rounded-lg px-3 py-2">
            <span class="text-sm text-slate-200 flex-1">
              {{ store.userSettings.favoriteReactionSystemName || `#${store.userSettings.favoriteReactionSystemId}` }}
            </span>
            <button @click="clearRxnSystem" class="text-slate-400 hover:text-red-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div v-else>
            <input
              v-model="rxnQuery"
              type="text"
              placeholder="Rechercher un système..."
              class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-cyan-500 placeholder-slate-500"
              @blur="onBlur('rxn')"
            />
            <div v-if="rxnDropdown && rxnResults.length > 0" class="absolute z-20 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-48 overflow-y-auto">
              <button
                v-for="sys in rxnResults"
                :key="sys.solarSystemId"
                @mousedown.prevent="selectRxnSystem(sys)"
                class="w-full px-3 py-2 text-left hover:bg-slate-700 text-sm flex items-center justify-between"
              >
                <span class="text-slate-200">{{ sys.solarSystemName }}</span>
                <span class="text-xs flex items-center gap-2">
                  <span :class="securityColor(sys.security)">{{ sys.security.toFixed(1) }}</span>
                  <span class="text-slate-500">{{ sys.regionName }}</span>
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
