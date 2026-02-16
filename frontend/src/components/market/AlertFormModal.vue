<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const props = defineProps<{
  visible: boolean
  typeName: string
  typeId: number
  currentSellPrice: number | null
  currentBuyPrice: number | null
}>()

const emit = defineEmits<{
  close: []
  submit: [payload: { typeId: number; direction: 'above' | 'below'; threshold: number; priceSource: string }]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

const direction = ref<'above' | 'below'>('below')
const threshold = ref<number>(0)
const priceSource = ref<string>('jita_sell')
const isSubmitting = ref(false)

// Reset form when modal opens
watch(() => props.visible, (val) => {
  if (val) {
    direction.value = 'below'
    threshold.value = props.currentSellPrice ?? 0
    priceSource.value = 'jita_sell'
    isSubmitting.value = false
  }
})

async function handleSubmit(): Promise<void> {
  if (isSubmitting.value || threshold.value <= 0) return
  isSubmitting.value = true

  try {
    emit('submit', {
      typeId: props.typeId,
      direction: direction.value,
      threshold: threshold.value,
      priceSource: priceSource.value,
    })
  } finally {
    isSubmitting.value = false
  }
}

function handleBackdropClick(): void {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="fixed inset-0 z-50 flex items-center justify-center"
    >
      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-slate-950/80"
        @click="handleBackdropClick"
      />

      <!-- Modal -->
      <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-md mx-4">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
          <h3 class="text-lg font-semibold text-slate-100">{{ t('market.alert.create') }}</h3>
          <button
            @click="emit('close')"
            class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          >
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <form @submit.prevent="handleSubmit" class="p-6 space-y-4">
          <!-- Item name (read-only) -->
          <div>
            <label class="block text-sm font-medium text-slate-400 mb-1">Item</label>
            <div class="flex items-center gap-2 px-3 py-2 bg-slate-800/50 rounded-lg border border-slate-700">
              <img
                :src="`https://images.evetech.net/types/${typeId}/icon?size=32`"
                :alt="typeName"
                class="w-6 h-6 rounded"
              />
              <span class="text-slate-200">{{ typeName }}</span>
            </div>
          </div>

          <!-- Current price reference -->
          <div class="flex gap-4 text-sm">
            <div>
              <span class="text-slate-500">Jita Sell:</span>
              <span class="ml-1 text-cyan-400 font-mono">{{ formatIsk(currentSellPrice) }}</span>
            </div>
            <div>
              <span class="text-slate-500">Jita Buy:</span>
              <span class="ml-1 text-emerald-400 font-mono">{{ formatIsk(currentBuyPrice) }}</span>
            </div>
          </div>

          <!-- Price Source -->
          <div>
            <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.priceSource') }}</label>
            <select
              v-model="priceSource"
              class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 focus:outline-none focus:border-cyan-500/50"
            >
              <option value="jita_sell">Jita Sell</option>
              <option value="jita_buy">Jita Buy</option>
            </select>
          </div>

          <!-- Direction -->
          <div>
            <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.direction') }}</label>
            <div class="flex gap-2">
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
            <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('market.alert.threshold') }} (ISK)</label>
            <input
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
              {{ t('market.alert.create') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>
