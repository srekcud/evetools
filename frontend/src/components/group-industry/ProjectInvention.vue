<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useAuthStore } from '@/stores/auth'
import { useGroupInventionStore } from '@/stores/group-industry/invention'
import type { ProjectInventionOption, ProjectInventionProduct } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const authStore = useAuthStore()
const inventionStore = useGroupInventionStore()

const inventorChoices = computed(() => (authStore.user?.characters ?? []).map(character => ({ id: character.id, name: character.name })))
const selectedInventorId = ref<string | null>(authStore.user?.characters.find(character => character.isMain)?.id ?? null)

function productLabel(product: ProjectInventionProduct): string {
  return product.typeName ?? `#${product.typeId}`
}

function cheapestOptionOf(product: ProjectInventionProduct): ProjectInventionOption | null {
  let cheapest: ProjectInventionOption | null = null
  for (const option of product.invention.options) {
    if (option.costPerUnit === null) continue
    if (cheapest === null || option.costPerUnit < (cheapest.costPerUnit ?? Infinity)) cheapest = option
  }
  return cheapest
}

function formatProbability(probability: number | null): string {
  return probability === null ? t('industry.inventionUnknown.label') : `${(probability * 100).toFixed(1)}%`
}

function formatAttempts(attempts: number | null): string {
  return attempts === null ? t('industry.inventionUnknown.label') : attempts.toFixed(1)
}

function formatCost(cost: number | null): string {
  return cost === null ? t('industry.inventionUnknown.label') : formatIsk(cost)
}

function formatDelta(delta: number | null): string {
  if (delta === null || delta === 0) return '—'
  return `${delta > 0 ? '+' : ''}${formatIsk(delta)}`
}

function deltaColor(delta: number | null): string {
  if (delta === null || delta === 0) return 'text-slate-500'
  return delta < 0 ? 'text-emerald-400' : 'text-red-400'
}

function optionKey(option: ProjectInventionOption): number | 'none' {
  return option.decryptorTypeId ?? 'none'
}

function onInventorChange(event: Event) {
  const characterId = (event.target as HTMLSelectElement).value
  selectedInventorId.value = characterId === '' ? null : characterId
}

watch([() => props.projectId, selectedInventorId], () => inventionStore.fetchInvention(props.projectId, selectedInventorId.value), { immediate: true })
</script>

<template>
  <section data-testid="invention" class="space-y-6">
    <p
      v-if="inventionStore.loading && inventionStore.products === null"
      data-testid="invention-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.invention.loading') }}</p>

    <div
      v-else-if="inventionStore.error !== null"
      data-testid="invention-error"
      role="alert"
      class="p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.invention.loadError') }}</p>
      <p class="mt-1 break-words">{{ inventionStore.error }}</p>
    </div>

    <p
      v-else-if="inventionStore.products !== null && inventionStore.products.length === 0"
      data-testid="invention-empty"
      class="p-8 text-center text-slate-400 bg-slate-900/80 rounded-xl border border-slate-800"
    >{{ t('groupIndustry.invention.empty') }}</p>

    <template v-else-if="inventionStore.products !== null">
      <article
        v-for="product in inventionStore.products"
        :key="product.typeId"
        :data-testid="`invention-product-${product.typeId}`"
        class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden"
        :class="{ 'opacity-60': inventionStore.loading }"
      >
        <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
          <div class="flex items-center gap-3 min-w-0">
            <img
              :src="getTypeIconUrl(product.typeId, 32)"
              alt=""
              class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
              @error="onImageError"
            />
            <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400 truncate">
              {{ t('groupIndustry.invention.title') }} · {{ productLabel(product) }}
            </h2>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <span data-testid="invention-product-units" class="px-2 py-1 rounded bg-slate-800 text-slate-300">{{ t('groupIndustry.invention.units', { count: product.runs }) }}</span>
            <span data-testid="invention-product-efficiency" class="px-2 py-1 rounded bg-slate-800 text-slate-300">{{ t('groupIndustry.invention.efficiency', { me: product.me, te: product.te }) }}</span>
          </div>
        </header>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 border-b border-slate-800 text-xs text-slate-500">
          <span>{{ t('industry.bpcKitTab.baseProbability') }}: <span class="text-cyan-400 font-mono">{{ formatProbability(product.invention.baseProbability) }}</span></span>
          <span>{{ t('industry.bpcKitTab.datacoresLabel') }}: <span class="text-slate-400">{{ product.invention.datacores.map(name => name ?? t('common.unknownName')).join(' + ') }}</span></span>
          <span>{{ t('industry.margins.datacoresCostPerAttempt') }}: <span class="text-slate-400 font-mono">{{ formatCost(product.invention.datacoresCostPerAttempt) }}</span></span>
          <label class="flex items-center gap-1">{{ t('industry.margins.inventorLabel') }}:
            <select
              data-testid="invention-inventor-select"
              class="bg-slate-800 border border-slate-700 rounded-sm text-slate-200 px-1.5 py-0.5"
              :value="selectedInventorId ?? ''"
              @change="onInventorChange"
            >
              <option value="">{{ t('industry.margins.withoutSkills') }}</option>
              <option v-for="inventor in inventorChoices" :key="inventor.id" :value="inventor.id">{{ inventor.name }}</option>
            </select>
          </label>
        </div>
        <p v-if="product.invention.unknownReason !== null" class="px-4 py-2 text-xs text-amber-400">
          {{ t(`industry.inventionUnknown.${product.invention.unknownReason}`) }}
        </p>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
                <th class="text-left py-2.5 px-3">{{ t('industry.bpcKitTab.decryptor') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.probability') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.bpcKitTab.runsBpc') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('groupIndustry.invention.copyEfficiency') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.attemptsCol') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.manufacturingJobsCol') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.inventionCostCol') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.totalProdCost') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.costPerUnitCol') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.deltaPerUnitCol') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
              <tr
                v-for="option in product.invention.options"
                :key="optionKey(option)"
                :data-testid="`invention-option-${product.typeId}-${optionKey(option)}`"
                class="hover:bg-slate-800/50"
                :class="{ 'bg-emerald-500/5 border-l-2 border-l-emerald-500': cheapestOptionOf(product) === option }"
              >
                <td class="py-2.5 px-3">
                  <span :class="option.decryptorTypeId === null ? 'text-slate-400 italic' : 'text-slate-200'">
                    {{ option.decryptorTypeId === null ? t('industry.bpcKitTab.none') : option.decryptorName }}
                  </span>
                  <span
                    v-if="cheapestOptionOf(product) === option"
                    data-testid="invention-cheapest"
                    class="ml-2 text-xs uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
                  >{{ t('groupIndustry.invention.cheapest') }}</span>
                  <span v-if="option.decryptorTypeId !== null" class="block text-xs text-slate-500 font-mono">{{ formatCost(option.decryptorCost) }} / {{ t('groupIndustry.invention.attempt') }}</span>
                </td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ formatProbability(option.probability) }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ option.runs }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ t('groupIndustry.invention.efficiency', { me: option.me, te: option.te }) }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ formatAttempts(option.expectedAttempts) }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ option.manufacturingJobs }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ formatCost(option.inventionCost) }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ formatCost(option.totalProductionCost) }}</td>
                <td class="py-2.5 px-3 text-right font-mono text-slate-100 font-semibold">{{ formatCost(option.costPerUnit) }}</td>
                <td class="py-2.5 px-3 text-right font-mono" :class="deltaColor(option.costPerUnitDelta)">{{ formatDelta(option.costPerUnitDelta) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500">{{ t('groupIndustry.invention.footnote') }}</p>
      </article>
    </template>
  </section>
</template>
