<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { parseIskValue } from '@/composables/useIskParser'
import type { BpcKitDecryptorOption } from '@/stores/industry/types'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()

const props = defineProps<{
  projectId: string
}>()

const store = useIndustryStore()
const { formatIsk, formatIskFull } = useFormatters()

const loading = ref(false)
const desiredBpcCount = ref(1)
const selectedDecryptorTypeId = ref<number | null | undefined>(undefined)
const applying = ref(false)
const editingCost = ref(false)
const costEditValue = ref('')
const costOverride = ref<number | null>(null)
const costInputRef = ref<HTMLInputElement | null>(null)

const bpcKit = computed(() => store.bpcKit)

const selectedInvention = computed(() => {
  if (!bpcKit.value?.inventions?.length) return null
  return bpcKit.value.inventions[0]
})

const selectedDecryptorOption = computed((): BpcKitDecryptorOption | null => {
  if (!selectedInvention.value) return null
  const options = selectedInvention.value.decryptorOptions
  if (selectedDecryptorTypeId.value === undefined) {
    // Default: select the best option
    const bestId = bpcKit.value?.summary.bestDecryptorTypeId
    return options.find(o => o.decryptorTypeId === (bestId ?? null)) ?? null
  }
  return options.find(o => o.decryptorTypeId === selectedDecryptorTypeId.value) ?? null
})

const summaryInventionLabel = computed(() => {
  if (!selectedDecryptorOption.value) return ''
  const name = selectedDecryptorOption.value.decryptorName
  const attempts = selectedDecryptorOption.value.expectedAttempts
  return `${name} \u00b7 ${attempts} ${attempts === 1 ? t('industry.bpcKitTab.attempt', 1) : t('industry.bpcKitTab.attempt', 2)}`
})

const totalInventionCost = computed(() => {
  if (!selectedDecryptorOption.value) return bpcKit.value?.summary.totalInventionCost ?? 0
  return selectedDecryptorOption.value.totalCost
})

// Materials needed for the selected decryptor option
const inventionMaterials = computed(() => {
  if (!selectedInvention.value || !selectedDecryptorOption.value) return []
  const attempts = selectedDecryptorOption.value.expectedAttempts
  const materials: { typeId: number; name: string; quantity: number }[] = []

  for (const dc of selectedInvention.value.datacores) {
    materials.push({
      typeId: dc.typeId,
      name: dc.typeName,
      quantity: dc.quantity * attempts,
    })
  }

  // Add decryptor if selected
  if (selectedDecryptorOption.value.decryptorTypeId != null) {
    materials.push({
      typeId: selectedDecryptorOption.value.decryptorTypeId,
      name: selectedDecryptorOption.value.decryptorName,
      quantity: attempts,
    })
  }

  return materials
})

function meTeColor(value: number): string {
  if (value < 0) return 'text-emerald-400'
  if (value > 0) return 'text-red-400'
  return 'text-slate-400'
}

function formatMeTe(value: number): string {
  if (value > 0) return `+${value}`
  if (value === 0) return '+0'
  return String(value)
}

function isBestOption(option: BpcKitDecryptorOption): boolean {
  return option.decryptorTypeId === (bpcKit.value?.summary.bestDecryptorTypeId ?? null)
}

function isSelected(option: BpcKitDecryptorOption): boolean {
  if (selectedDecryptorTypeId.value === undefined) {
    return isBestOption(option)
  }
  return option.decryptorTypeId === selectedDecryptorTypeId.value
}

function selectOption(option: BpcKitDecryptorOption) {
  selectedDecryptorTypeId.value = option.decryptorTypeId
}

async function loadBpcKit() {
  loading.value = true
  try {
    await store.fetchBpcKit(props.projectId, desiredBpcCount.value)
    // Reset selection to best when reloading
    selectedDecryptorTypeId.value = undefined
  } finally {
    loading.value = false
  }
}

async function markBpcStepsPurchased() {
  const project = store.currentProject
  if (!project?.steps) return
  const bpcSteps = project.steps.filter(s => s.activityType === 'copy' && s.depth > 0)
  for (const step of bpcSteps) {
    if (!step.purchased) {
      try {
        await store.toggleStepPurchased(props.projectId, step.id, true)
      } catch {
        // Ignore individual step errors
      }
    }
  }
}

async function applyToProject() {
  applying.value = true
  try {
    await markBpcStepsPurchased()

    const patchData: Record<string, unknown> = { bpoCost: costOverride.value ?? totalInventionCost.value }

    // For T2 items, also update ME/TE based on selected decryptor
    if (bpcKit.value?.isT2 && selectedDecryptorOption.value) {
      patchData.meLevel = selectedDecryptorOption.value.me
      patchData.teLevel = selectedDecryptorOption.value.te
    }

    // Send invention materials to be included in shopping list
    patchData.inventionMaterials = inventionMaterials.value.map(m => ({
      typeId: m.typeId,
      typeName: m.name,
      quantity: m.quantity,
    }))

    await store.updateProject(props.projectId, patchData)

    // Refresh project since steps may have been regenerated (ME/TE change)
    if (patchData.meLevel != null) {
      await store.fetchProject(props.projectId)
    }
  } finally {
    applying.value = false
  }
}

function startEditCost() {
  costEditValue.value = String(Math.round(costOverride.value ?? totalInventionCost.value))
  editingCost.value = true
  nextTick(() => {
    costInputRef.value?.select()
  })
}

function confirmEditCost() {
  const value = parseIskValue(costEditValue.value)
  if (value != null && value > 0) {
    costOverride.value = value
  }
  editingCost.value = false
}

function cancelEditCost() {
  editingCost.value = false
}

function clearCostOverride() {
  costOverride.value = null
}

// Re-fetch when desired BPC count changes (debounced)
let debounceTimer: ReturnType<typeof setTimeout> | null = null
watch(desiredBpcCount, (newVal) => {
  if (newVal < 1) return
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    loadBpcKit()
  }, 500)
})

onMounted(() => {
  loadBpcKit()
})
</script>

<template>
  <div class="space-y-4">
    <!-- Loading state -->
    <div v-if="loading && !bpcKit" class="text-center py-12">
      <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      <p class="text-slate-500 text-sm">{{ t('industry.bpcKitTab.loading') }}</p>
    </div>

    <template v-else-if="bpcKit">
      <!-- Loading overlay for re-fetch -->
      <div v-if="loading" class="flex items-center gap-2 text-sm text-slate-500 px-1">
        <svg class="w-4 h-4 animate-spin text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('industry.bpcKitTab.loading') }}
      </div>

      <!-- Section: Invention - Decryptor Comparison -->
      <div v-if="bpcKit.isT2 && selectedInvention" class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800">
          <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
              <h4 class="text-sm font-semibold text-slate-200">
                {{ selectedInvention.productName }} &mdash; {{ t('industry.bpcKitTab.invention') }}
              </h4>
            </div>
            <div class="flex items-center gap-4 text-xs text-slate-500">
              <span>
                {{ t('industry.bpcKitTab.baseProbability') }}:
                <span class="text-cyan-400 font-mono">{{ (selectedInvention.baseProbability * 100).toFixed(1) }}%</span>
              </span>
              <span class="text-slate-700">|</span>
              <span>
                {{ t('industry.bpcKitTab.datacoresLabel') }}:
                <span class="text-slate-400">
                  {{ selectedInvention.datacores.map(d => d.typeName).join(' + ') }}
                </span>
              </span>
            </div>
          </div>
        </div>

        <div>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
                <th class="text-center py-2.5 px-2 w-8"></th>
                <th class="text-left py-2.5 px-3">{{ t('industry.bpcKitTab.decryptor') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.me') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.te') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.runsBpc') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.bpcKitTab.adjProb') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.bpcKitTab.costPerAttempt') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.bpcKitTab.expAttempts') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.bpcKitTab.totalCost') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
              <tr
                v-for="option in selectedInvention.decryptorOptions"
                :key="option.decryptorTypeId ?? 'none'"
                :class="[
                  'relative group cursor-pointer transition-colors',
                  isBestOption(option) ? 'bg-emerald-500/5 hover:bg-emerald-500/10 border-l-2 border-l-emerald-500' : '',
                  !isBestOption(option) && isSelected(option) ? 'bg-cyan-500/10 hover:bg-cyan-500/15' : '',
                  !isBestOption(option) && !isSelected(option) ? 'hover:bg-slate-800/50' : '',
                ]"
                @click="selectOption(option)"
              >
                <!-- Radio -->
                <td class="py-2.5 px-2 text-center">
                  <div
                    v-if="isSelected(option)"
                    class="w-3.5 h-3.5 rounded-full border-2 border-cyan-500 mx-auto flex items-center justify-center"
                    style="box-shadow: 0 0 0 2px rgba(6, 182, 212, 0.3)"
                  >
                    <div class="w-1.5 h-1.5 rounded-full bg-cyan-400"></div>
                  </div>
                  <div
                    v-else
                    class="w-3.5 h-3.5 rounded-full border border-slate-600 mx-auto"
                  ></div>
                </td>

                <!-- Decryptor name -->
                <td class="py-2.5 px-3">
                  <div v-if="option.decryptorTypeId == null" class="text-slate-400 italic">
                    {{ t('industry.bpcKitTab.none') }}
                  </div>
                  <div v-else class="flex items-center gap-2">
                    <span :class="[isBestOption(option) ? 'text-slate-100 font-semibold' : 'text-slate-200']">
                      {{ option.decryptorName }}
                    </span>
                    <span
                      v-if="isBestOption(option)"
                      class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
                    >
                      {{ t('industry.bpcKitTab.best') }}
                    </span>
                  </div>
                </td>

                <!-- ME -->
                <td class="py-2.5 px-2 text-center font-mono" :class="[meTeColor(-option.me), isBestOption(option) ? 'font-semibold' : '']">
                  {{ formatMeTe(-option.me) }}
                </td>

                <!-- TE -->
                <td class="py-2.5 px-2 text-center font-mono" :class="[meTeColor(-option.te), isBestOption(option) ? 'font-semibold' : '']">
                  {{ formatMeTe(-option.te) }}
                </td>

                <!-- Runs -->
                <td class="py-2.5 px-2 text-center font-mono" :class="isBestOption(option) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ option.runs }}
                </td>

                <!-- Adj. Probability -->
                <td class="py-2.5 px-3 text-right font-mono" :class="isBestOption(option) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ (option.probability * 100).toFixed(1) }}%
                </td>

                <!-- Cost per attempt (with tooltip) -->
                <td class="py-2.5 px-3 text-right font-mono relative" :class="isBestOption(option) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ formatIsk(option.costPerAttempt) }}
                  <!-- Cost breakdown tooltip -->
                  <div class="hidden group-hover:block absolute right-0 top-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl p-3 w-56 text-xs z-50">
                    <div class="space-y-1.5">
                      <div class="flex justify-between">
                        <span class="text-slate-400">{{ t('industry.bpcKitTab.datacoresLabel') }}</span>
                        <span class="font-mono text-slate-200">{{ formatIsk(option.costBreakdown.datacores) }}</span>
                      </div>
                      <div class="flex justify-between">
                        <span class="text-slate-400">{{ t('industry.bpcKitTab.decryptor') }}</span>
                        <span class="font-mono" :class="option.costBreakdown.decryptor > 0 ? 'text-slate-200' : 'text-slate-500'">
                          {{ option.costBreakdown.decryptor > 0 ? formatIsk(option.costBreakdown.decryptor) : '--' }}
                        </span>
                      </div>
                      <div class="flex justify-between">
                        <span class="text-slate-400">{{ t('industry.bpcKitTab.t1BpcCopy') }}</span>
                        <span class="font-mono text-slate-200">{{ formatIsk(option.costBreakdown.copyCost) }}</span>
                      </div>
                      <div class="flex justify-between">
                        <span class="text-slate-400">{{ t('industry.bpcKitTab.installCost') }}</span>
                        <span class="font-mono text-slate-200">{{ formatIsk(option.costBreakdown.inventionInstall) }}</span>
                      </div>
                      <div class="border-t border-slate-700 pt-1.5 flex justify-between font-semibold">
                        <span class="text-slate-300">Total</span>
                        <span class="font-mono text-slate-100">{{ formatIsk(option.costPerAttempt) }}</span>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Expected attempts -->
                <td class="py-2.5 px-3 text-right font-mono" :class="isBestOption(option) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ option.expectedAttempts }}
                </td>

                <!-- Total cost -->
                <td class="py-2.5 px-3 text-right font-mono" :class="[
                  isBestOption(option) ? 'text-emerald-400 font-bold' : 'text-slate-200',
                  isSelected(option) && !isBestOption(option) ? 'text-cyan-400 font-semibold' : '',
                ]">
                  {{ formatIsk(option.totalCost) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer: Cost + Apply -->
        <div class="px-4 py-3 border-t border-slate-800 flex items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.bpcKitTab.inventionCosts') }}</span>
            <!-- Double-click to override cost -->
            <span
              v-if="!editingCost"
              class="text-lg font-bold font-mono text-cyan-400 cursor-pointer hover:text-cyan-300 transition-colors"
              :title="t('industry.bpcKitTab.doubleClickOverride')"
              @dblclick="startEditCost"
            >
              {{ formatIskFull(costOverride ?? totalInventionCost) }}
            </span>
            <input
              v-else
              ref="costInputRef"
              v-model="costEditValue"
              type="text"
              class="w-40 bg-slate-800 border border-cyan-500 rounded-sm px-2 py-1 text-sm font-mono text-cyan-400 focus:outline-none"
              @keydown.enter="confirmEditCost"
              @keydown.escape="cancelEditCost"
              @blur="confirmEditCost"
            />
            <span v-if="costOverride != null" class="text-xs text-amber-500 cursor-pointer hover:text-amber-400" @click="clearCostOverride">
              &#10005; reset
            </span>
          </div>
          <div class="flex items-center gap-3">
            <div v-if="selectedDecryptorOption" class="text-xs text-slate-500 text-right">
              {{ summaryInventionLabel }}
            </div>
            <button
              @click="applyToProject"
              :disabled="applying || store.currentProject?.status === 'completed'"
              class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-cyan-800 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors whitespace-nowrap"
            >
              <LoadingSpinner v-if="applying" size="sm" />
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              {{ t('industry.bpcKitTab.applyToProject') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Not T2 message -->
      <div v-if="!bpcKit.isT2" class="eve-card p-6 text-center">
        <p class="text-slate-500 text-sm">{{ t('industry.bpcKitTab.notT2') }}</p>
      </div>

    </template>
  </div>
</template>
