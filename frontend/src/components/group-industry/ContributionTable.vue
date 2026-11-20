<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { usePilotName } from '@/composables/usePilotName'
import type { GroupContribution, ContributionType, ContributionStatus, EditContributionInput } from '@/stores/group-industry/types'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import UnknownContributionValue from '@/components/group-industry/UnknownContributionValue.vue'
import PriceSourceBadge from '@/components/industry/PriceSourceBadge.vue'

const { t } = useI18n()

const props = defineProps<{
  projectId: string
  isAdmin: boolean
}>()

const store = useGroupContributionStore()
const { formatIsk, formatNumber } = useFormatters()
const { getCharacterPortraitUrl, getTypeIconUrl, onImageError } = useEveImages()
const { pilotName } = usePilotName()

type WalletProofFilter = 'all' | 'backed' | 'none'
type WalletProofState = 'complete' | 'partial' | 'none'

const typeFilter = ref<ContributionType | 'all'>('all')
const statusFilter = ref<ContributionStatus | 'all'>('all')
const walletProofFilter = ref<WalletProofFilter>('all')

const filteredContributions = computed(() => {
  return store.contributions.filter(c => {
    if (typeFilter.value !== 'all' && c.type !== typeFilter.value) return false
    if (statusFilter.value !== 'all' && c.status !== statusFilter.value) return false
    if (walletProofFilter.value === 'backed' && !hasWalletProof(c)) return false
    if (walletProofFilter.value === 'none' && hasWalletProof(c) !== false) return false
    return true
  })
})

// Summary stats
function sumOrUnknown(contributions: GroupContribution[]): number | null {
  let sum = 0
  for (const contribution of contributions) {
    if (contribution.estimatedValue === null) return null
    sum += contribution.estimatedValue
  }
  return sum
}

const totalValue = computed(() => sumOrUnknown(store.contributions))
const approvedValue = computed(() => sumOrUnknown(store.contributions.filter(c => c.status === 'approved')))
const pendingValue = computed(() => sumOrUnknown(store.contributions.filter(c => c.status === 'pending')))
const hasUnknownValue = computed(() => store.contributions.some(c => c.estimatedValue === null))
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
  courier: 'bg-sky-500/[0.12] text-sky-400 border-sky-500/25',
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
  courier: 'Courier',
}

const STATUS_LABELS: Record<ContributionStatus, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
}

function methodLabelKey(contribution: GroupContribution): string {
  if (contribution.isAutoDetected && contribution.type === 'material') return 'groupIndustry.contributions.methods.paidPrice'
  if (contribution.isAutoDetected && contribution.type === 'job_install') return 'groupIndustry.contributions.methods.esiCost'
  if (contribution.type === 'material') {
    return contribution.valuedAtJitaPrice
      ? 'groupIndustry.contributions.methods.depositDayPrice'
      : 'groupIndustry.contributions.methods.paidPrice'
  }
  return 'groupIndustry.contributions.methods.enteredValue'
}

type WalletProof = { state: WalletProofState; label: string }

function walletProof(contribution: GroupContribution): WalletProof | null {
  const backed = contribution.walletBackedQuantity
  if (contribution.type !== 'material' || backed === undefined || backed === null) return null
  if (backed <= 0) return { state: 'none', label: t('groupIndustry.contributions.badges.noWalletProof') }
  if (backed >= contribution.quantity) return { state: 'complete', label: t('groupIndustry.contributions.badges.walletBacked') }
  return {
    state: 'partial',
    label: t('groupIndustry.contributions.badges.walletPartial', { backed: formatNumber(backed, 0), total: formatNumber(contribution.quantity, 0) }),
  }
}

function hasWalletProof(contribution: GroupContribution): boolean | null {
  const proof = walletProof(contribution)
  return proof === null ? null : proof.state !== 'none'
}

const WALLET_PROOF_BADGE_CLASSES: Record<WalletProofState, string> = {
  complete: 'bg-emerald-500/10 text-emerald-400/70 border-emerald-500/15',
  partial: 'bg-amber-500/10 text-amber-400/70 border-amber-500/15',
  none: 'bg-red-500/10 text-red-400/70 border-red-500/15',
}

function isAwaitingUnits(contribution: GroupContribution): boolean {
  return contribution.awaitingQuantity !== undefined && contribution.awaitingQuantity > 0
}

function isValueReadFromEsi(contribution: GroupContribution): boolean {
  if (contribution.type !== 'material') return contribution.isAutoDetected
  if (isAwaitingUnits(contribution)) return false
  return contribution.isAutoDetected || contribution.valueSource === 'wallet_purchase'
}

async function handleValueAtJitaPrice(id: string): Promise<void> {
  await store.valueContributionAtJitaPrice(props.projectId, id)
}

function isPending(status: ContributionStatus): boolean {
  return status === 'pending'
}

function isDeliveryProposal(contrib: GroupContribution): boolean {
  return contrib.isAutoDetected && contrib.type === 'material'
}

function isDeliveryConfirmedByHand(contrib: GroupContribution): boolean {
  return contrib.status === 'approved' && isDeliveryProposal(contrib) && !contrib.isVerified
}

const editingId = ref<string | null>(null)
const withdrawingId = ref<string | null>(null)
const editQuantity = ref(0)
const editValue = ref<number | null>(null)
const editNote = ref('')
const isSavingEdit = ref(false)

function hasEnteredValue(contribution: GroupContribution): boolean {
  return contribution.type !== 'material'
}

function startEdit(contribution: GroupContribution): void {
  store.clearError()
  withdrawingId.value = null
  editingId.value = contribution.id
  editQuantity.value = contribution.quantity
  editValue.value = contribution.estimatedValue
  editNote.value = contribution.note ?? ''
}

function cancelEdit(): void {
  editingId.value = null
}

function changedFields(contribution: GroupContribution): EditContributionInput {
  const input: EditContributionInput = {}
  if (editQuantity.value !== contribution.quantity) input.quantity = editQuantity.value
  if (hasEnteredValue(contribution) && editValue.value !== null && editValue.value !== contribution.estimatedValue) input.estimatedValue = editValue.value
  if (editNote.value !== (contribution.note ?? '')) input.note = editNote.value
  return input
}

async function saveEdit(contribution: GroupContribution): Promise<void> {
  const input = changedFields(contribution)
  if (Object.keys(input).length === 0) {
    cancelEdit()
    return
  }
  isSavingEdit.value = true
  const saved = await store.editContribution(props.projectId, contribution.id, input)
  isSavingEdit.value = false
  if (saved) cancelEdit()
}

function askWithdrawal(id: string): void {
  store.clearError()
  editingId.value = null
  withdrawingId.value = id
}

async function confirmWithdrawal(id: string): Promise<void> {
  const withdrawn = await store.withdrawContribution(props.projectId, id)
  if (withdrawn || store.error !== null) withdrawingId.value = null
}

async function handleApprove(id: string): Promise<void> {
  await store.approveContribution(props.projectId, id)
}

async function handleReject(id: string): Promise<void> {
  await store.rejectContribution(props.projectId, id)
}

async function handleReopen(id: string): Promise<void> {
  await store.reopenContribution(props.projectId, id)
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
        :aria-label="t('a11y.zoneB.groupIndustry.contributionTypeFilter')"
        class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
      >
        <option value="all">{{ t('common.status.all') }} Types</option>
        <option v-for="(label, key) in TYPE_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select
        v-model="statusFilter"
        :aria-label="t('a11y.zoneB.groupIndustry.contributionStatusFilter')"
        class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
      >
        <option value="all">{{ t('common.status.all') }} Statuses</option>
        <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select
        v-model="walletProofFilter"
        data-testid="wallet-proof-filter"
        :aria-label="t('a11y.zoneB.groupIndustry.contributionWalletProofFilter')"
        class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
      >
        <option value="all">{{ t('groupIndustry.contributions.walletProofFilter.all') }}</option>
        <option value="backed">{{ t('groupIndustry.contributions.walletProofFilter.backed') }}</option>
        <option value="none">{{ t('groupIndustry.contributions.walletProofFilter.none') }}</option>
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
    <svg class="w-10 h-10 mx-auto text-slate-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
    </svg>
    <p class="text-sm text-slate-500">No contributions yet</p>
    <p class="text-xs text-slate-500 mt-1">Contributions will appear here once members start contributing</p>
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
                    :alt="pilotName(contrib.memberCharacterName)"
                    class="w-8 h-8 rounded-full border border-slate-700"
                    @error="onImageError"
                  />
                  <span class="text-slate-200 font-medium">{{ pilotName(contrib.memberCharacterName) }}</span>
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
                    <input
                      v-if="editingId === contrib.id"
                      v-model="editNote"
                      data-testid="edit-note"
                      type="text"
                      maxlength="500"
                      :aria-label="t('groupIndustry.contributions.own.note')"
                      class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-2 py-1 text-xs text-slate-300 focus:border-cyan-500 focus:outline-none"
                    />
                    <p v-else-if="contrib.note && contrib.bomItemTypeName" class="text-xs text-slate-500">{{ contrib.note }}</p>
                  </div>
                </div>
              </td>
              <!-- Quantity -->
              <td class="py-3 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                <input
                  v-if="editingId === contrib.id"
                  v-model.number="editQuantity"
                  data-testid="edit-quantity"
                  type="number"
                  min="1"
                  :aria-label="t('groupIndustry.contributions.own.quantity')"
                  class="w-24 bg-slate-800 border border-slate-700 rounded px-2 py-1 text-right text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
                />
                <template v-else>{{ formatNumber(contrib.quantity, 0) }}</template>
              </td>
              <!-- Valuation -->
              <td
                class="py-3 px-3 text-right font-mono max-w-[10rem]"
                :class="isPending(contrib.status) ? 'text-amber-400' : 'text-emerald-400'"
                style="font-variant-numeric: tabular-nums;"
              >
                <input
                  v-if="editingId === contrib.id && hasEnteredValue(contrib)"
                  v-model.number="editValue"
                  data-testid="edit-value"
                  type="number"
                  min="0"
                  :aria-label="t('groupIndustry.contributions.own.value')"
                  class="w-32 bg-slate-800 border border-slate-700 rounded px-2 py-1 text-right text-sm text-slate-300 focus:border-cyan-500 focus:outline-none"
                />
                <template v-else-if="contrib.awaitingQuantity !== undefined && contrib.awaitingQuantity > 0">
                  <template v-if="contrib.estimatedValue !== null">{{ formatIsk(contrib.estimatedValue) }}</template>
                  <div class="flex items-center justify-end gap-1.5 text-xs font-sans text-violet-300" data-testid="awaiting-units">
                    <UnknownContributionValue />
                    <span>{{ t('groupIndustry.contributions.awaitingUnits', { count: formatNumber(contrib.awaitingQuantity, 0) }) }}</span>
                    <span class="text-slate-500">{{ t('groupIndustry.contributions.valueUnknown') }}</span>
                  </div>
                </template>
                <UnknownContributionValue v-else-if="contrib.estimatedValue === null" />
                <template v-else>
                  {{ formatIsk(contrib.estimatedValue) }}
                  <div><PriceSourceBadge :source="contrib.estimatedValueSource" /></div>
                </template>
              </td>
              <!-- Method -->
              <td class="py-3 px-3">
                <div data-testid="method-badges" class="flex flex-wrap items-center gap-1.5">
                  <span class="whitespace-nowrap text-xs text-slate-500">
                    {{ t(methodLabelKey(contrib)) }}
                  </span>
                  <span
                    v-if="isValueReadFromEsi(contrib)"
                    class="whitespace-nowrap text-xs px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400/70 border border-emerald-500/15"
                  >ESI &#10003;</span>
                </div>
              </td>
              <!-- Status -->
              <td class="py-3 px-3 text-center">
                <div data-testid="status-badges" class="flex flex-wrap items-center justify-center gap-1.5">
                  <span
                    class="whitespace-nowrap text-xs px-2 py-0.5 rounded border font-medium"
                    :class="STATUS_BADGE_CLASSES[contrib.status]"
                  >
                    {{ STATUS_LABELS[contrib.status] }}
                  </span>
                  <span
                    v-if="contrib.isAutoDetected"
                    class="whitespace-nowrap text-xs px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400/70 border border-emerald-500/15"
                  >{{ t('groupIndustry.contributions.badges.autoDetected') }}</span>
                  <span
                    v-if="walletProof(contrib) !== null"
                    data-testid="wallet-proof-badge"
                    class="whitespace-nowrap text-xs px-1.5 py-0.5 rounded border"
                    :class="WALLET_PROOF_BADGE_CLASSES[walletProof(contrib)!.state]"
                  >{{ walletProof(contrib)!.label }}</span>
                  <span
                    v-if="!contrib.isVerified && (contrib.isAutoDetected || isPending(contrib.status))"
                    class="whitespace-nowrap text-xs px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400/70 border border-amber-500/15"
                  >{{ t('groupIndustry.contributions.badges.unverified') }}</span>
                  <span
                    v-if="isDeliveryConfirmedByHand(contrib)"
                    class="whitespace-nowrap text-xs px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400/70 border border-amber-500/15"
                  >{{ t('groupIndustry.contributions.badges.deliveryConfirmedByHand') }}</span>
                  <span
                    v-if="contrib.duplicatesDerived"
                    class="whitespace-nowrap text-xs px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400/70 border border-amber-500/15"
                  >{{ t('groupIndustry.contributions.duplicatesDerived') }}</span>
                </div>
              </td>
              <!-- Actions -->
              <td class="py-3 px-6 text-center">
                <button
                  v-if="contrib.switchableToJitaPrice"
                  class="mb-1.5 px-2.5 py-1 rounded bg-violet-600/20 text-violet-300 text-xs font-medium hover:bg-violet-600/30 transition-colors border border-violet-600/30"
                  @click="handleValueAtJitaPrice(contrib.id)"
                >{{ t('groupIndustry.contributions.valueAtJitaPrice') }}</button>
                <div
                  v-if="editingId === contrib.id"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    :disabled="isSavingEdit"
                    class="px-2.5 py-1 rounded bg-cyan-600/20 text-cyan-400 text-xs font-medium hover:bg-cyan-600/30 transition-colors border border-cyan-600/30 disabled:opacity-50"
                    @click="saveEdit(contrib)"
                  >{{ t('common.actions.save') }}</button>
                  <button
                    class="px-2.5 py-1 rounded bg-slate-600/20 text-slate-300 text-xs font-medium hover:bg-slate-600/30 transition-colors border border-slate-600/30"
                    @click="cancelEdit"
                  >{{ t('common.actions.cancel') }}</button>
                </div>
                <div
                  v-else-if="withdrawingId === contrib.id"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    class="px-2.5 py-1 rounded bg-red-600/20 text-red-400 text-xs font-medium hover:bg-red-600/30 transition-colors border border-red-600/30"
                    @click="confirmWithdrawal(contrib.id)"
                  >{{ t('groupIndustry.contributions.own.confirmWithdraw') }}</button>
                  <button
                    class="px-2.5 py-1 rounded bg-slate-600/20 text-slate-300 text-xs font-medium hover:bg-slate-600/30 transition-colors border border-slate-600/30"
                    @click="withdrawingId = null"
                  >{{ t('common.actions.cancel') }}</button>
                </div>
                <div
                  v-else-if="contrib.editableByViewer"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    class="px-2.5 py-1 rounded bg-slate-600/20 text-slate-300 text-xs font-medium hover:bg-slate-600/30 transition-colors border border-slate-600/30"
                    @click="startEdit(contrib)"
                  >{{ t('groupIndustry.contributions.own.edit') }}</button>
                  <button
                    class="px-2.5 py-1 rounded bg-red-600/20 text-red-400 text-xs font-medium hover:bg-red-600/30 transition-colors border border-red-600/30"
                    @click="askWithdrawal(contrib.id)"
                  >{{ t('groupIndustry.contributions.own.withdraw') }}</button>
                </div>
                <div
                  v-else-if="isAdmin && isPending(contrib.status)"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    class="px-2.5 py-1 rounded bg-emerald-600/20 text-emerald-400 text-xs font-medium hover:bg-emerald-600/30 transition-colors border border-emerald-600/30 disabled:opacity-40 disabled:cursor-not-allowed"
                    :disabled="isAwaitingUnits(contrib)"
                    :title="isAwaitingUnits(contrib) ? t('groupIndustry.contributions.approveBlockedAwaiting') : undefined"
                    @click="handleApprove(contrib.id)"
                  >{{ isDeliveryProposal(contrib) ? 'Confirm delivery' : 'Approve' }}</button>
                  <button
                    class="px-2.5 py-1 rounded bg-red-600/20 text-red-400 text-xs font-medium hover:bg-red-600/30 transition-colors border border-red-600/30"
                    @click="handleReject(contrib.id)"
                  >Reject</button>
                </div>
                <div
                  v-else-if="isAdmin && contrib.reopenable"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    class="px-2.5 py-1 rounded bg-slate-600/20 text-slate-300 text-xs font-medium hover:bg-slate-600/30 transition-colors border border-slate-600/30"
                    @click="handleReopen(contrib.id)"
                  >Reopen</button>
                </div>
                <div
                  v-else-if="isAdmin && contrib.duplicatesDerived"
                  class="flex items-center justify-center gap-1.5"
                >
                  <button
                    class="px-2.5 py-1 rounded bg-red-600/20 text-red-400 text-xs font-medium hover:bg-red-600/30 transition-colors border border-red-600/30"
                    @click="handleReject(contrib.id)"
                  >Reject</button>
                </div>
                <span v-else-if="!contrib.switchableToJitaPrice" class="text-xs text-slate-500">--</span>
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
            <p class="text-xl font-mono font-bold text-slate-100" style="font-variant-numeric: tabular-nums;"><UnknownContributionValue v-if="totalValue === null" /><template v-else>{{ formatIsk(totalValue) }}</template></p>
          </div>
          <div class="w-px h-10 bg-slate-800"></div>
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Approved Value</p>
            <p class="text-xl font-mono font-bold text-emerald-400" style="font-variant-numeric: tabular-nums;"><UnknownContributionValue v-if="approvedValue === null" /><template v-else>{{ formatIsk(approvedValue) }}</template></p>
          </div>
          <div class="w-px h-10 bg-slate-800"></div>
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Pending Value</p>
            <p class="text-xl font-mono font-bold text-amber-400" style="font-variant-numeric: tabular-nums;"><UnknownContributionValue v-if="pendingValue === null" /><template v-else>{{ formatIsk(pendingValue) }}</template></p>
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
      <p v-if="hasUnknownValue" class="mt-3 text-xs text-violet-300">{{ t('groupIndustry.contributions.unknownValueCause') }}</p>
    </div>
  </template>

  <!-- Error -->
  <div v-if="store.error" class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
    <p class="text-sm text-red-400">{{ store.error }}</p>
  </div>
</template>
