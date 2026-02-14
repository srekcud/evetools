<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { safeJsonParse } from '@/services/api'
import { useFormatters } from '@/composables/useFormatters'
import ShoppingListResults from '@/components/shopping/ShoppingListResults.vue'
import type { ShoppingItem, ShoppingTotals } from '@/components/shopping/ShoppingListResults.vue'

interface SharedListResponse {
  token: string
  items: ShoppingItem[]
  notFound: string[]
  totals: ShoppingTotals
  transportCostPerM3: number
  structureId: number | null
  structureName: string | null
  createdAt: string
  expiresAt: string
}

const { t } = useI18n()
const route = useRoute()
const { formatDateTime } = useFormatters()

const isLoading = ref(true)
const error = ref('')
const data = ref<SharedListResponse | null>(null)

const token = computed(() => route.params.token as string)

onMounted(async () => {
  await loadSharedList()
})

async function loadSharedList() {
  isLoading.value = true
  error.value = ''

  try {
    const response = await fetch(`/api/shopping-list/shared/${token.value}`, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (!response.ok) {
      if (response.status === 404) {
        throw new Error(t('shopping.listExpired'))
      }
      throw new Error(t('shopping.loadError'))
    }

    data.value = await safeJsonParse<SharedListResponse>(response)
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('common.errors.loadFailed')
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900">
    <div class="max-w-6xl mx-auto px-4 py-8">
      <!-- Header -->
      <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
          <svg class="w-8 h-8 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
          </svg>
          <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-400">
            {{ t('shopping.sharedList') }}
          </h1>
        </div>
        <p class="text-slate-400 text-sm">
          EVE Tools - Shopping List
        </p>
      </div>

      <!-- Loading -->
      <div v-if="isLoading" class="flex justify-center py-20">
        <svg class="animate-spin h-8 w-8 text-cyan-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="bg-red-900/30 border border-red-500/30 rounded-xl p-6 text-center">
        <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <p class="text-red-400 text-lg font-medium">{{ error }}</p>
        <a
          href="/shopping-list"
          class="inline-block mt-4 px-6 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors"
        >
          {{ t('shopping.createNewList') }}
        </a>
      </div>

      <!-- Content -->
      <div v-else-if="data" class="space-y-6">
        <!-- Info banner -->
        <div class="bg-slate-900 border border-slate-800 rounded-lg p-4 flex items-center justify-between">
          <div class="text-sm text-slate-400">
            <span>{{ t('shopping.createdAt') }} {{ formatDateTime(data.createdAt) }}</span>
            <span class="mx-2">•</span>
            <span>{{ t('shopping.expiresAt') }} {{ formatDateTime(data.expiresAt) }}</span>
          </div>
          <a
            href="/shopping-list"
            class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors"
          >
            {{ t('shopping.createMyOwn') }}
          </a>
        </div>

        <!-- Results -->
        <ShoppingListResults
          :items="data.items"
          :totals="data.totals"
          :structure-name="data.structureName || 'C-J6MT Keepstar'"
          :not-found="data.notFound"
          :readonly="true"
        />
      </div>

      <!-- Footer -->
      <div class="mt-12 text-center text-slate-500 text-sm">
        <p>EVE Tools - Utilitaires pour EVE Online</p>
      </div>
    </div>
  </div>
</template>
