<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PiTierBadge from '@/components/planetary/PiTierBadge.vue'
import { exportedColonyTemplate, useColonyLayoutExport } from '@/composables/useColonyLayoutExport'
import { usePlanetaryLayoutLibraryStore, type LibraryLayout } from '@/stores/planetaryLayoutLibrary'
import { usePlanetaryLayoutsStore } from '@/stores/planetaryLayouts'

const ALL_PLANETS = ''

const { t, te } = useI18n()
const store = usePlanetaryLayoutLibraryStore()
const layoutsStore = usePlanetaryLayoutsStore()
const { copied, failed, lastCopiedLayoutId, copyTemplate } = useColonyLayoutExport()

const planetFilter = ref(ALL_PLANETS)
const productFilter = ref('')
const addFeedback = ref<{ layoutId: string, message: string | null } | null>(null)

const planets = computed(() => {
  const byId = new Map<number, string>()
  for (const entry of store.entries) {
    byId.set(entry.summary.planetTypeId, planetLabelOf(entry))
  }
  return [...byId.entries()]
    .map(([typeId, label]) => ({ value: String(typeId), label }))
    .sort((left, right) => left.label.localeCompare(right.label))
})

const visibleEntries = computed(() => {
  const productNeedle = productFilter.value.trim().toLowerCase()
  return store.entries.filter(entry => {
    if (planetFilter.value !== ALL_PLANETS && String(entry.summary.planetTypeId) !== planetFilter.value) return false
    if (productNeedle === '') return true
    return entry.summary.products.some(product => nameOfType(product.typeId, product.typeName).toLowerCase().includes(productNeedle))
  })
})

onMounted(() => store.load())

function planetLabelOf(entry: LibraryLayout): string {
  return entry.summary.planetTypeName ?? `#${entry.summary.planetTypeId}`
}

function nameOfType(typeId: number, typeName: string | null): string {
  return typeName ?? `#${typeId}`
}

function errorMessageOf(code: string): string {
  const key = `pi.layouts.errors.${code}`
  return te(key) ? t(key) : code
}

async function onCopy(entry: LibraryLayout): Promise<void> {
  await copyTemplate(entry.id, entry.name, entry.gameTemplate)
}

async function onAdd(entry: LibraryLayout): Promise<void> {
  const errors = await layoutsStore.importLayout(exportedColonyTemplate(entry.name, entry.gameTemplate), entry.name)
  addFeedback.value = { layoutId: entry.id, message: errors.length === 0 ? null : errorMessageOf(errors[0].code) }
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="layout-library">
    <div class="mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.layouts.library.title') }}</h3>
      <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.layouts.library.subtitle') }}</p>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <label class="block min-w-0 flex-1 basis-48">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.layouts.library.planetLabel') }}</span>
        <select
          v-model="planetFilter"
          data-testid="library-filter-planet"
          class="mt-1 w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white focus:border-cyan-500 focus:outline-hidden"
        >
          <option :value="ALL_PLANETS">{{ t('pi.layouts.library.allPlanets') }}</option>
          <option v-for="planet in planets" :key="planet.value" :value="planet.value">{{ planet.label }}</option>
        </select>
      </label>
      <label class="block min-w-0 flex-1 basis-48">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.layouts.library.productLabel') }}</span>
        <input
          v-model="productFilter"
          type="text"
          data-testid="library-filter-product"
          :placeholder="t('pi.layouts.library.productPlaceholder')"
          class="mt-1 w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white focus:border-cyan-500 focus:outline-hidden"
        />
      </label>
    </div>

    <p v-if="store.error" class="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="library-error">{{ store.error }}</p>

    <p v-if="store.entries.length === 0 && !store.isLoading && !store.error" class="rounded-md border border-dashed border-slate-700 px-4 py-6 text-center text-sm text-slate-400" data-testid="library-empty">{{ t('pi.layouts.library.empty') }}</p>
    <p v-else-if="store.entries.length > 0 && visibleEntries.length === 0" class="rounded-md border border-dashed border-slate-700 px-4 py-6 text-center text-sm text-slate-400" data-testid="library-no-match">{{ t('pi.layouts.library.noMatch') }}</p>

    <div class="grid gap-3 lg:grid-cols-2">
      <article
        v-for="entry in visibleEntries"
        :key="entry.id"
        class="min-w-0 rounded-lg border border-slate-700 bg-slate-800/60 p-4"
        data-testid="library-card"
        :data-layout-id="entry.id"
      >
        <div class="flex min-w-0 items-baseline justify-between gap-3">
          <h4 class="min-w-0 truncate text-base font-semibold text-slate-100" data-testid="library-name" :title="entry.name">{{ entry.name }}</h4>
          <span class="shrink-0 font-mono-tech text-xs text-cyan-300" data-testid="library-planet">{{ planetLabelOf(entry) }}</span>
        </div>
        <p v-if="entry.mine" class="mt-1 text-xs uppercase tracking-wider text-emerald-300" data-testid="library-mine">{{ t('pi.layouts.library.mine') }}</p>

        <div class="mt-3 flex min-w-0 flex-col gap-1" data-testid="library-products">
          <span
            v-for="product in entry.summary.products"
            :key="product.typeId"
            class="flex min-w-0 items-center gap-2 text-sm text-slate-100"
          >
            <PiTierBadge :tier="product.tier" />
            <span class="min-w-0 truncate" :title="nameOfType(product.typeId, product.typeName)">{{ nameOfType(product.typeId, product.typeName) }}</span>
          </span>
          <span v-if="entry.summary.products.length === 0" class="text-xs text-slate-500">{{ t('pi.layouts.card.noProduct') }}</span>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
          <span v-if="copied && lastCopiedLayoutId === entry.id" class="mr-auto text-xs text-emerald-300" role="status">{{ t('pi.layouts.card.copied') }}</span>
          <span v-else-if="failed && lastCopiedLayoutId === entry.id" class="mr-auto text-xs text-amber-300" role="alert">{{ t('pi.layouts.card.copyFailed') }}</span>
          <span v-else-if="addFeedback?.layoutId === entry.id && addFeedback.message === null" class="mr-auto text-xs text-emerald-300" role="status" data-testid="library-added">{{ t('pi.layouts.library.added') }}</span>
          <span v-else-if="addFeedback?.layoutId === entry.id" class="mr-auto min-w-0 text-xs text-amber-300" role="alert" data-testid="library-add-error">{{ addFeedback.message }}</span>
          <button
            type="button"
            data-testid="library-copy"
            class="rounded-md border border-cyan-500/40 px-2 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10"
            @click="onCopy(entry)"
          >{{ t('pi.layouts.card.copy') }}</button>
          <button
            type="button"
            data-testid="library-add"
            class="rounded-md border border-emerald-500/40 px-2 py-1 text-xs text-emerald-300 transition-colors hover:bg-emerald-500/10"
            @click="onAdd(entry)"
          >{{ t('pi.layouts.library.add') }}</button>
        </div>
      </article>
    </div>
  </section>
</template>
