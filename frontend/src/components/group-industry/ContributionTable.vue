<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { ContributionType, ContributionStatus } from '@/stores/group-industry/types'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()

const props = defineProps<{
  projectId: string
  isAdmin: boolean
}>()

const store = useGroupContributionStore()
const { formatIsk, formatNumber } = useFormatters()
const { getCharacterPortraitUrl, getTypeIconUrl, onImageError } = useEveImages()

const typeFilter = ref<ContributionType | 'all'>('all')
const statusFilter = ref<ContributionStatus | 'all'>('all')

const filteredContributions = computed(() => {
  return store.contributions.filter(c => {
    if (typeFilter.value !== 'all' && c.type !== typeFilter.value) return false
    if (statusFilter.value !== 'all' && c.status !== statusFilter.value) return false
    return true
  })
})

// Summary stats
const totalValue = computed(() =>
  store.contributions.reduce((sum, c) => sum + c.estimatedValue, 0),
)
const approvedValue = computed(() =>
  store.contributions.filter(c => c.status === 'approved').reduce((sum, c) => sum + c.estimatedValue, 0),
)
const pendingValue = computed(() =>
  store.contributions.filter(c => c.status === 'pending').reduce((sum, c) => sum + c.estimatedValue, 0),
)
const approvedCount = computed(() =>
  store.contributions.filter(c => c.status === 'approved').length,
)
const pendingCount = computed(() =>
  store.contributions.filter(c => c.status === 'pending').length,
)
const rejectedCount = computed(() =>
  store.contributions.filter(c => c.status === 'rejected').length,
)

const TYPE_BADGE_CLASSES: Record<ContributionType, string> = {
  material: 'bg-cyan-500/[0.12] text-cyan-400 border-cyan-500/25',
  job_install: 'bg-amber-500/[0.12] text-amber-400 border-amber-500/25',
  bpc: 'bg-violet-500/[0.12] text-violet-400 border-violet-500/25',
  line_rental: 'bg-pink-500/[0.12] text-pink-400 border-pink-500/25',
}

const STATUS_BADGE_CLASSES: Record<ContributionStatus, string> = {
  pending: 'bg-amber-500/[0.12] text-amber-400 border-amber-500/25',
  approved: 'bg-emerald-500/[0.12] text-emerald-400 border-emerald-500/25',
  rejected: 'bg-red-500/[0.12] text-red-400 border-red-500/25',
}

const TYPE_LABELS: Record<ContributionType, string> = {
  material: 'Material',
  job_install: 'Job Install',
  bpc: 'BPC',
  line_rental: 'Line Rental',
}

const STATUS_LABELS: Record<ContributionStatus, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
}

function isPending(status: ContributionStatus): boolean {
  return status === 'pending'
}

async function handleApprove(id: string): Promise<void> {
  await store.approveContribution(props.projectId, id)
}

async function handleReject(id: string): Promise<void> {
  await store.rejectContribution(props.projectId, id)
}

onMounted(() => {
  store.fetchContributions(props.projectId)
})
</script>

<template>
  <!-- Filters & Actions -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <select
        v-model="typeFilter"
        class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
      >
        <option value="all">{{ t('common.status.all') }} Types</option>
        <option v-for="(label, key) in TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select
        v-model="statusFilter"
        class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
      >
        <option value="all">{{ t('common.status.all') }} Statuses</option>
        <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
    </div>
    <slot name="actions" />
  </div>

  <!-- Loading -->
  <div v-if="store.loading" class="flex items-center justify-center py-12">
    <LoadingSpinner size="lg" class="text-cyan-400" />
  </div>

  <!-- Empty state -->
  <div
    v-else-if="filteredContributions.length === 0"
    class="bg-slate-900/80 rounded-xl border border-slate-800 p-12 text-center"
  >
    <svg class="w-10 h-10 mx-auto text-slate-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
    </svg>
    <p class="text-sm text-slate-500">No contributions yet</p>
    <p class="text-xs text-slate-600 mt-1">Contributions will appear here once members start contributing</p>
  </div>

  <!-- Contributions Table -->
  <template v-else>
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
              <th class="text-left py-3 px-6">Character</th>
              <th class="text-left py-3 px-3">Type</th>
              <th class="text-left py-3 px-3">Item / Description</th>
              <th class="text-right py-3 px-3">Qty</th>
              <th class="text-right py-3 px-3">Valuation</th>
              <th class="text-left py-3 px-3">Method</th>
              <th class="text-center py-3 px-3">Status</th>
              <th class="text-center py-3 px-6">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/30">
            <tr
              v-for="contrib in filteredContributions"
              :key="contrib.id"
              class="hover:bg-slate-800/30"
              :class="{ 'bg-amber-500/[0.02]': isPending(contrib.status) }"
            >
              <!-- Character -->
              <td class="py-3 px-6">
                <div class="flex items-center gap-2.5">
                  <img
                    :src="getCharacterPortraitUrl(0, 32)"
                    :alt="contrib.memberCharacterName"
                    class="w-8 h-8 rounded-full border border-slate-700"
                    @error="onImageError"
                  />
                  <span class="text-slate-200 font-medium">{{ contrib.memberCharacterName }}</span>
                </div>
              </td>
              <!-- Type Badge -->
              <td class="py-3 px-3">
                <span
                  class="text-xs px-2 py-0.5 rounded border font-medium"
                  :class="TYPE_BADGE_CLASSES[contrib.type]"
                >
                  {{ TYPE_LABELS[contrib.type] }}
                </span>
              </td>
              <!-- Item / Description -->
              <td class="py-3 px-3">
                <div class="flex items-center gap-2">
                  <div
                    v-if="contrib.bomItemTypeName"
                    class="w-6 h-6 rounded bg-slate-800 border border-slate-700 overflow-hidden flex-shrink-0"
                  >
                    <img
                      v-if="contrib.bomItemId"
                      :src="getTypeIconUrl(0, 32)"
                      :alt="contrib.bomItemTypeName"
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <div>
                    <span class="text-slate-300">{{ contrib.bomItemTypeName || contrib.note || '--' }}</span>
                    <p v-if="contrib.note && contrib.bomItemTypeName" class="text-xs text-slate-500">{{ contrib.note }}</p>
                  </div>
                </div>
              </td>
              <!-- Quantity -->
              <td class="py-3 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(contrib.quantity, 0) }}
              </td>
              <!-- Valuation -->
              <td
                class="py-3 px-3 text-right font-mono"
                :class="isPending(contrib.status) ? 'text-amber-400' : 'text-emerald-400'"
                style="font-variant-numeric: tabular-nums;"
              >
                {{ formatIsk(contrib.estimatedValue) }}
              </td>
              <!-- Method -->
              <td class="py-3 px-3">
                <span class="text-xs text-slate-500">
                  {{ contrib.type === 'material' ? 'Jita weighted' : contrib.type === 'job_install' ? 'ESI job cost' : contrib.type === 'bpc' ? 'Invention cost' : 'Line rental rate' }}
                </span>
                <span
                  v-if="contrib.isAutoDetected"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400/70 border border-emerald-500/15 ml-1.5"
                >ESI &#10003;</span>
              </td>
              <!-- Status -->
              <td class="py-3 px-3 text-center">
                <span
                  class="text-xs px-2 py-0.5 rounded border font-medium"
                  :class="STATUS_BADGE_CLASSES[contrib.status]"
                >
                  {{ STATUS_LABELS[contrib.status] }}
                </span>
                <span
                  v-if="contrib.isAutoDetected"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400/70 border border-emerald-500/15 ml-1.5"
                >Auto-detected</span>
                <span
                  v-else-if="!contrib.isVerified && isPending(contrib.status)"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400/70 border border-amber-500/15 ml-1.5"
                >Unverified</span>
              </td>
              <!-- Actions -->
              <td class="py-3 px-6 text-center">
                <div
                  v-if="isAdmin && isPending(contrib.status)"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    class="px-2.5 py-1 rounded bg-emerald-600/20 text-emerald-400 text-xs font-medium hover:bg-emerald-600/30 transition-colors border border-emerald-600/30"
                    @click="handleApprove(contrib.id)"
                  >Approve</button>
                  <button
                    class="px-2.5 py-1 rounded bg-red-600/20 text-red-400 text-xs font-medium hover:bg-red-600/30 transition-colors border border-red-600/30"
                    @click="handleReject(contrib.id)"
                  >Reject</button>
                </div>
                <span v-else class="text-xs text-slate-600">--</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Summary Bar -->
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-5 mt-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-8">
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Total Engaged Costs</p>
            <p class="text-xl font-mono font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">{{ formatIsk(totalValue) }}</p>
          </div>
          <div class="w-px h-10 bg-slate-800"></div>
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Approved Value</p>
            <p class="text-xl font-mono font-bold text-emerald-400" style="font-variant-numeric: tabular-nums;">{{ formatIsk(approvedValue) }}</p>
          </div>
          <div class="w-px h-10 bg-slate-800"></div>
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Pending Value</p>
            <p class="text-xl font-mono font-bold text-amber-400" style="font-variant-numeric: tabular-nums;">{{ formatIsk(pendingValue) }}</p>
          </div>
        </div>
        <div class="flex items-center gap-4 text-xs text-slate-500">
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-400"></span> Approved: {{ approvedCount }}
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-amber-400"></span> Pending: {{ pendingCount }}
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-red-400"></span> Rejected: {{ rejectedCount }}
          </div>
        </div>
      </div>
    </div>
  </template>

  <!-- Error -->
  <div v-if="store.error" class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
    <p class="text-sm text-red-400">{{ store.error }}</p>
  </div>
</template>
