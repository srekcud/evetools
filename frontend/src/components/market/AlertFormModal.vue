<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { MarketAlert, StructurePrice } from '@/stores/market'

const props = defineProps<{
  visible: boolean
  typeName: string
  typeId: number
  currentSellPrice: number | null
  currentBuyPrice: number | null
  structurePrices?: StructurePrice[]
  editingAlert?: MarketAlert | null
}>()

function extractSystemName(name: string | null | undefined): string {
  if (!name) return 'Structure'
  const dash = name.indexOf(' - ')
  return dash > 0 ? name.substring(0, dash) : name
}

const emit = defineEmits<{
  close: []
  submit: [payload: { typeId: number; direction: 'above' | 'below'; threshold: number; priceSource: string; structureId?: number }]
  update: [payload: { id: string; direction: 'above' | 'below'; threshold: number }]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const priceSourceId = useId()
const structureId = useId()
const directionLabelId = useId()
const thresholdId = useId()

const direction = ref<'above' | 'below'>('below')
const threshold = ref<number>(0)
const priceSource = ref<string>('jita_sell')
const selectedStructureId = ref<number | null>(null)
const isSubmitting = ref(false)

const structureOptions = computed(() => props.structurePrices ?? [])
const hasStructure = computed(() => structureOptions.value.length > 0)
const selectedStructure = computed(() => structureOptions.value.find(option => option.structureId === selectedStructureId.value) ?? structureOptions.value[0] ?? null)
const selectedSystemName = computed(() => extractSystemName(selectedStructure.value?.structureName))
const isStructureSource = computed(() => priceSource.value === 'structure_sell' || priceSource.value === 'structure_buy')
const showStructureSelector = computed(() => isStructureSource.value && structureOptions.value.length > 1)

const isEditing = computed(() => props.editingAlert != null)

const modalTitle = computed(() => isEditing.value ? t('market.alert.edit') : t('market.alert.create'))

const submitLabel = computed(() => {
  if (props.editingAlert == null) return t('market.alert.create')
  return props.editingAlert.status === 'triggered' ? t('market.alert.rearm') : t('common.actions.save')
})

const priceSourceLabel = computed(() => {
  switch (props.editingAlert?.priceSource) {
    case 'jita_sell': return 'Jita Sell'
    case 'jita_buy': return 'Jita Buy'
    case 'structure_sell': return extractSystemName(props.editingAlert?.structureName) + ' Sell'
    case 'structure_buy': return extractSystemName(props.editingAlert?.structureName) + ' Buy'
    default: return props.editingAlert?.priceSource ?? ''
  }
})

// Reset form when modal opens
watch(() => props.visible, (val) => {
  if (val) {
    if (props.editingAlert != null) {
      direction.value = props.editingAlert.direction
      threshold.value = props.editingAlert.threshold
      priceSource.value = props.editingAlert.priceSource
    } else {
      direction.value = 'below'
      threshold.value = props.currentSellPrice ?? 0
      priceSource.value = 'jita_sell'
      selectedStructureId.value = structureOptions.value[0]?.structureId ?? null
    }
    isSubmitting.value = false
  }
})

async function handleSubmit(): Promise<void> {
  if (isSubmitting.value || threshold.value <= 0) return
  isSubmitting.value = true

  try {
    if (props.editingAlert != null) {
      emit('update', {
        id: props.editingAlert.id,
        direction: direction.value,
        threshold: threshold.value,
      })
    } else {
      const payload = {
        typeId: props.typeId,
        direction: direction.value,
        threshold: threshold.value,
        priceSource: priceSource.value,
      }
      emit('submit', isStructureSource.value && selectedStructure.value !== null
        ? { ...payload, structureId: selectedStructure.value.structureId }
        : payload)
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :show="visible"
    :title="modalTitle"
    panel-class="border-cyan-500/30 shadow-cyan-500/10 max-w-md"
    @close="emit('close')"
  >
    <!-- Header -->
    <template #title="{ titleId }">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
        <h3 :id="titleId" class="text-lg font-semibold text-slate-100">{{ modalTitle }}</h3>
        <button
          @click="emit('close')"
          class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          :aria-label="t('common.actions.close')"
        >
          <svg aria-hidden="true" class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </template>

    <!-- Content -->
    <form @submit.prevent="handleSubmit" class="p-6 space-y-4">
      <!-- Item name (read-only) -->
      <div>
        <span class="block text-sm font-medium text-slate-400 mb-1">Item</span>
        <div class="flex items-center gap-2 px-3 py-2 bg-slate-800/50 rounded-lg border border-slate-700">
          <img
            :src="getTypeIconUrl(typeId, 32)"
            @error="onImageError"
            :alt="typeName"
            class="w-6 h-6 rounded shrink-0"
          />
          <span class="text-slate-200 truncate" :title="typeName">{{ typeName }}</span>
        </div>
      </div>

      <!-- Current price reference -->
      <div v-if="editingAlert" class="text-sm">
        <span class="text-slate-500">{{ t('market.alert.currentPrice') }}:</span>
        <span class="ml-1 text-cyan-400 font-mono">{{ formatIsk(editingAlert.currentPrice) }}</span>
      </div>
      <div v-else class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <div>
          <span class="text-slate-500">Jita Sell:</span>
          <span class="ml-1 text-cyan-400 font-mono">{{ formatIsk(currentSellPrice) }}</span>
        </div>
        <div>
          <span class="text-slate-500">Jita Buy:</span>
          <span class="ml-1 text-emerald-400 font-mono">{{ formatIsk(currentBuyPrice) }}</span>
        </div>
        <template v-if="selectedStructure">
          <div>
            <span class="text-slate-500">{{ selectedSystemName }} Sell:</span>
            <span class="ml-1 text-cyan-400 font-mono">{{ formatIsk(selectedStructure.sell) }}</span>
          </div>
          <div>
            <span class="text-slate-500">{{ selectedSystemName }} Buy:</span>
            <span class="ml-1 text-emerald-400 font-mono">{{ formatIsk(selectedStructure.buy) }}</span>
          </div>
        </template>
      </div>

      <!-- Price Source -->
      <div v-if="editingAlert">
        <span class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.priceSource') }}</span>
        <div class="px-3 py-2 bg-slate-800/50 rounded-lg border border-slate-700 text-slate-200" :title="t('market.alert.readOnlyHint')">
          {{ priceSourceLabel }}
        </div>
      </div>
      <div v-else>
        <label :for="priceSourceId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.priceSource') }}</label>
        <select
          :id="priceSourceId"
          v-model="priceSource"
          class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 focus:outline-none focus:border-cyan-500/50"
        >
          <option value="jita_sell">Jita Sell</option>
          <option value="jita_buy">Jita Buy</option>
          <option v-if="hasStructure" value="structure_sell">{{ selectedSystemName }} Sell</option>
          <option v-if="hasStructure" value="structure_buy">{{ selectedSystemName }} Buy</option>
        </select>
      </div>

      <div v-if="!editingAlert && showStructureSelector">
        <label :for="structureId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.structure') }}</label>
        <select
          :id="structureId"
          v-model.number="selectedStructureId"
          class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 focus:outline-none focus:border-cyan-500/50"
        >
          <option v-for="option in structureOptions" :key="option.structureId" :value="option.structureId">{{ option.structureName }}</option>
        </select>
      </div>

      <!-- Direction -->
      <div>
        <span :id="directionLabelId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.direction') }}</span>
        <div role="group" :aria-labelledby="directionLabelId" class="flex gap-2">
          <button
            type="button"
            @click="direction = 'above'"
            class="flex-1 px-4 py-2 rounded-lg text-sm font-medium transition-colors border"
            :class="direction === 'above'
              ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
              : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-slate-600'"
          >
            {{ t('market.alert.above') }}
          </button>
          <button
            type="button"
            @click="direction = 'below'"
            class="flex-1 px-4 py-2 rounded-lg text-sm font-medium transition-colors border"
            :class="direction === 'below'
              ? 'bg-red-500/20 text-red-400 border-red-500/30'
              : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-slate-600'"
          >
            {{ t('market.alert.below') }}
          </button>
        </div>
      </div>

      <!-- Threshold -->
      <div>
        <label :for="thresholdId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.threshold') }} (ISK)</label>
        <input
          :id="thresholdId"
          v-model.number="threshold"
          type="number"
          min="0"
          step="0.01"
          class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 font-mono focus:outline-none focus:border-cyan-500/50"
        />
      </div>

      <!-- Submit -->
      <div class="flex gap-3 pt-2">
        <button
          type="button"
          @click="emit('close')"
          class="flex-1 px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors border border-slate-700"
        >
          {{ t('common.actions.cancel') }}
        </button>
        <button
          type="submit"
          :disabled="isSubmitting || threshold <= 0"
          class="flex-1 px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors"
        >
          {{ submitLabel }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
