<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isValidCoverageWeeks, usePlanetarySettingsStore, type HomeLocationCandidate, type HubChoice } from '@/stores/planetarySettings'
import { parseDecimalInput } from '@/utils/planetaryPlanner'

const { t } = useI18n()
const store = usePlanetarySettingsStore()

const isWeeksInvalid = ref(false)
const weeksText = ref('')

const homeLocationValue = computed(() => String(store.settings?.homeLocationId ?? ''))
const hubValue = computed(() => String(store.settings?.hubStructureId ?? ''))
const coverageWeeksValue = computed(() => String(store.settings?.coverageWeeks ?? ''))

watch(coverageWeeksValue, value => {
  weeksText.value = value
}, { immediate: true })

const homeLocationOptions = computed<HomeLocationCandidate[]>(() => {
  const settings = store.settings
  if (settings === null || settings.homeLocationId === null || settings.homeLocationName === null) return store.locations
  if (store.locations.some(location => location.locationId === settings.homeLocationId)) return store.locations

  return [{ locationId: settings.homeLocationId, locationName: settings.homeLocationName, solarSystemName: null }, ...store.locations]
})

const hubOptions = computed<HubChoice[]>(() => {
  const settings = store.settings
  if (settings === null || store.hubChoices.length === 0) return []
  if (store.hubChoices.some(choice => choice.id === settings.hubStructureId)) return store.hubChoices

  return [{ id: settings.hubStructureId, name: settings.hubStructureName }, ...store.hubChoices]
})

async function onHomeLocationChange(event: Event): Promise<void> {
  const select = event.target as HTMLSelectElement
  const saved = await store.saveHomeLocation(select.value === '' ? null : Number(select.value))
  if (!saved) select.value = homeLocationValue.value
}

async function onHubChange(event: Event): Promise<void> {
  const select = event.target as HTMLSelectElement
  const saved = await store.saveHub(Number(select.value))
  if (!saved) select.value = hubValue.value
}

async function onCoverageWeeksChange(event: Event): Promise<void> {
  weeksText.value = (event.target as HTMLInputElement).value
  const weeks = parseDecimalInput(weeksText.value)
  isWeeksInvalid.value = !isValidCoverageWeeks(weeks)
  if (isWeeksInvalid.value) return

  await store.saveCoverageWeeks(weeks)
  weeksText.value = coverageWeeksValue.value
}

function fieldClass(isInvalid: boolean): string[] {
  return [
    'w-full rounded-md border bg-slate-800 px-3 py-1.5 font-mono-tech text-sm text-white focus:outline-hidden',
    isInvalid ? 'border-red-500/60 focus:border-red-400' : 'border-slate-700 focus:border-cyan-500',
  ]
}

onMounted(() => store.load())
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="stock-settings">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.settings.title') }}</h3>

    <p v-if="store.error" class="mb-4 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="stock-settings-error">
      {{ store.error }}
    </p>

    <div v-if="store.settings" class="space-y-4">
      <label class="block">
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.stock.settings.homeLocation') }}</span>
        <select
          data-testid="stock-home-location"
          :value="homeLocationValue"
          :class="fieldClass(false)"
          @change="onHomeLocationChange"
        >
          <option value="">{{ t('pi.stock.settings.homeLocationNone') }}</option>
          <option v-for="location in homeLocationOptions" :key="location.locationId" :value="String(location.locationId)">
            {{ location.locationName }}
          </option>
        </select>
        <span class="mt-1 block text-xs text-slate-500">{{ t('pi.stock.settings.homeLocationHint') }}</span>
      </label>

      <label v-if="hubOptions.length > 0" class="block">
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.stock.settings.hub') }}</span>
        <select
          data-testid="stock-hub"
          :value="hubValue"
          :class="fieldClass(false)"
          @change="onHubChange"
        >
          <option v-for="choice in hubOptions" :key="choice.id" :value="String(choice.id)">{{ choice.name }}</option>
        </select>
      </label>

      <div v-else class="block">
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.stock.settings.hub') }}</span>
        <p class="font-mono-tech text-sm text-white" data-testid="stock-hub-readonly">{{ store.settings.hubStructureName }}</p>
        <span class="mt-1 block text-xs text-slate-500">{{ t('pi.stock.settings.hubHint') }}</span>
      </div>

      <label class="block">
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.stock.settings.coverageWeeks') }}</span>
        <div class="flex items-center gap-2">
          <input
            type="text"
            inputmode="numeric"
            data-testid="stock-coverage-weeks"
            :value="weeksText"
            :class="[...fieldClass(isWeeksInvalid), 'w-24']"
            :aria-invalid="isWeeksInvalid"
            @change="onCoverageWeeksChange"
          />
          <span class="text-sm text-slate-400">{{ t('pi.stock.settings.weeksUnit') }}</span>
        </div>
        <span :class="['mt-1 block text-xs', isWeeksInvalid ? 'text-red-400' : 'text-slate-500']">{{ t('pi.stock.settings.coverageWeeksHint') }}</span>
      </label>
    </div>
  </section>
</template>
