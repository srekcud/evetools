<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useTypeNames } from '@/composables/useTypeNames'
import { usePlanetaryProjectNeedsStore } from '@/stores/planetaryProjectNeeds'

const ICON_SIZE = 32

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { typeNameLabel } = useTypeNames()
const { getTypeIconUrl, onImageError } = useEveImages()
const store = usePlanetaryProjectNeedsStore()
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-stock-project-needs">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.projectNeeds.title') }}</h3>

    <p v-if="store.error" class="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="project-needs-error">{{ store.error }}</p>

    <p v-else-if="store.products.length === 0" class="text-sm text-slate-500" data-testid="project-needs-empty">{{ t('pi.stock.projectNeeds.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs uppercase tracking-wider text-slate-500">
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.projectNeeds.product') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.projectNeeds.required') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.projectNeeds.stock') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.projectNeeds.missing') }}</th>
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.projectNeeds.projects') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="product in store.products" :key="product.typeId" :data-testid="`project-need-row-${product.typeId}`" class="transition-colors hover:bg-slate-800/30">
            <td class="py-2.5">
              <div class="flex items-center gap-2">
                <img :src="getTypeIconUrl(product.typeId, ICON_SIZE)" alt="" class="h-6 w-6 shrink-0 rounded-sm" @error="onImageError" />
                <span class="text-slate-100">{{ typeNameLabel(product.typeName, product.typeId) }}</span>
              </div>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`project-need-cell-required-${product.typeId}`">{{ formatNumber(product.required, 0) }}</td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`project-need-cell-stock-${product.typeId}`">
              <UnknownValue v-if="product.stock === null" />
              <template v-else>{{ formatNumber(product.stock, 0) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`project-need-cell-missing-${product.typeId}`">
              <UnknownValue v-if="product.missing === null" />
              <template v-else>{{ formatNumber(product.missing, 0) }}</template>
            </td>
            <td class="py-2.5">
              <ul class="flex flex-wrap gap-x-3 gap-y-1">
                <li v-for="project in product.projects" :key="project.projectId">
                  <RouterLink
                    :to="{ name: 'industry-project', params: { id: project.projectId } }"
                    class="text-cyan-400 hover:text-cyan-300"
                    :data-testid="`project-need-project-${product.typeId}-${project.projectId}`"
                  >{{ project.projectName ?? project.projectId }}</RouterLink>
                  <span class="ml-1 font-mono-tech text-xs text-slate-500">{{ formatNumber(project.remaining, 0) }}</span>
                </li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
