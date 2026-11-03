<script setup lang="ts">
import { computed, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMiningOperationStore, type MiningBoostShipOption, type MiningCompressorKind } from '@/stores/miningOperation'

export interface MiningBoostFit {
  boostShipTypeId: number | null
  mountedCompressorKinds: MiningCompressorKind[]
}

const props = defineProps<{
  modelValue: MiningBoostFit
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [fit: MiningBoostFit]
}>()

const { t } = useI18n()
const store = useMiningOperationStore()
const groupName = useId()

const chosenShip = computed<MiningBoostShipOption | null>(() => store.boostShips.find(ship => ship.typeId === props.modelValue.boostShipTypeId) ?? null)

function chooseShip(ship: MiningBoostShipOption): void {
  emit('update:modelValue', { boostShipTypeId: ship.typeId, mountedCompressorKinds: ship.compressors.map(compressor => compressor.kind) })
}

function clearShip(): void {
  emit('update:modelValue', { boostShipTypeId: null, mountedCompressorKinds: [] })
}

function toggleCompressor(kind: MiningCompressorKind, mounted: boolean): void {
  const ship = chosenShip.value
  if (ship === null) return
  const kinds = ship.compressors.map(compressor => compressor.kind).filter(candidate => candidate === kind ? mounted : props.modelValue.mountedCompressorKinds.includes(candidate))
  emit('update:modelValue', { boostShipTypeId: ship.typeId, mountedCompressorKinds: kinds })
}

onMounted(() => {
  if (store.boostShips.length === 0) void store.fetchBoostShips()
})
</script>

<template>
  <fieldset class="space-y-2" :disabled="disabled">
    <legend class="mb-1 text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.boostFit.label') }}</legend>
    <div class="flex flex-wrap items-center gap-2">
      <label v-for="ship in store.boostShips" :key="ship.typeId" class="flex cursor-pointer items-center gap-1.5 rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-200 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10">
        <input type="radio" :name="groupName" :value="ship.typeId" :checked="modelValue.boostShipTypeId === ship.typeId" :disabled="disabled" class="accent-cyan-400" @change="chooseShip(ship)">
        {{ ship.name }}
      </label>
      <button v-if="modelValue.boostShipTypeId !== null && !disabled" type="button" class="rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-400 hover:bg-slate-800" @click="clearShip">{{ t('miningOperation.boostFit.notDeclared') }}</button>
    </div>
    <div v-if="chosenShip !== null" class="flex flex-wrap gap-2">
      <label v-for="compressor in chosenShip.compressors" :key="compressor.kind" class="flex cursor-pointer items-center gap-1.5 rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-200 has-[:checked]:border-amber-400 has-[:checked]:bg-amber-500/10" :title="compressor.name">
        <input type="checkbox" :value="compressor.kind" :checked="modelValue.mountedCompressorKinds.includes(compressor.kind)" :disabled="disabled" class="accent-amber-400" @change="toggleCompressor(compressor.kind, ($event.target as HTMLInputElement).checked)">
        {{ t(`miningOperation.boostFit.kind.${compressor.kind}`) }}
      </label>
    </div>
  </fieldset>
</template>
