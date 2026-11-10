<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import MainLayout from '@/layouts/MainLayout.vue'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import MoonTaxTab from '@/components/ledger/moon-tax/MoonTaxTab.vue'
import MiningModuleTabs from '@/components/mining-module/MiningModuleTabs.vue'
import MiningSubTabs from '@/components/mining-module/MiningSubTabs.vue'
import NewMiningOperationModal from '@/components/mining-operation/NewMiningOperationModal.vue'
import { moonChunkStatusAt, useCorporationMiningExtractions, type CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { useTabQuery } from '@/composables/useTabQuery'

type MoonsTab = 'calendar' | 'tax'

const MOONS_TABS = ['calendar', 'tax'] as const satisfies readonly MoonsTab[]
const CHUNK_QUERY_KEY = 'chunk'

const activeTab = useTabQuery<MoonsTab>(MOONS_TABS, 'calendar')
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const extractions = useCorporationMiningExtractions()

const creationChunk = ref<CorporationMiningExtraction | null>(null)
const chunkUnavailable = ref(false)

const subTabs = computed(() => MOONS_TABS.map(tab => ({ id: tab, label: t(`miningModule.moons.tabs.${tab}`) })))

function chunkRequestedInUrl(): string | null {
  const requested = route.query[CHUNK_QUERY_KEY]
  return typeof requested === 'string' && requested !== '' ? requested : null
}

function queryWithoutChunk() {
  return Object.fromEntries(Object.entries(route.query).filter(([key]) => key !== CHUNK_QUERY_KEY))
}

async function openRequestedChunk(chunkId: string): Promise<void> {
  await extractions.load()
  const found = extractions.corporations.value.flatMap(corporation => corporation.extractions).find(extraction => extraction.id === chunkId)
  if (found === undefined || moonChunkStatusAt(found, new Date()) === 'exploded') {
    chunkUnavailable.value = true
  } else {
    creationChunk.value = found
  }
  await router.replace({ query: queryWithoutChunk() })
}

function openCreatedOperation(operationId: string): void {
  creationChunk.value = null
  void router.push({ name: 'mining-operation-detail', params: { id: operationId } })
}

onMounted(async () => {
  const chunkId = chunkRequestedInUrl()
  if (chunkId !== null) await openRequestedChunk(chunkId)
})
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
      <MiningModuleTabs active="moons" />

      <h1 class="sr-only">{{ t('miningModule.pages.moons') }}</h1>

      <MiningSubTabs v-model="activeTab" :tabs="subTabs" />

      <p v-if="chunkUnavailable" class="mb-4 rounded-md border border-slate-700 bg-slate-900 px-4 py-2 text-sm text-slate-200" role="status" data-testid="moons-chunk-unavailable">{{ t('miningOperation.list.chunkUnavailable') }}</p>

      <MoonChunksTab v-if="activeTab === 'calendar'" />

      <MoonTaxTab v-else />
    </div>

    <NewMiningOperationModal :show="creationChunk !== null" :chunk="creationChunk ?? undefined" @close="creationChunk = null" @open="openCreatedOperation" />
  </MainLayout>
</template>
