<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupContractSalesStore } from '@/stores/group-industry/contractSales'
import type { ContractSaleCandidate } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  recordingDisabled?: boolean
}>()

const { t } = useI18n()
const { formatIskExact, formatNumber, formatDateTime } = useFormatters()
const store = useGroupContractSalesStore()

const linkingContractId = ref<number | null>(null)
const linkError = ref<string | null>(null)

async function link(candidate: ContractSaleCandidate): Promise<void> {
  linkingContractId.value = candidate.contractId
  linkError.value = null
  try {
    await store.linkContract(props.projectId, candidate)
  } catch (e) {
    linkError.value = e instanceof Error ? e.message : t('groupIndustry.contractSales.linkFailed')
    return
  } finally {
    linkingContractId.value = null
  }
  await store.fetchForProject(props.projectId)
}

onMounted(() => {
  store.fetchForProject(props.projectId)
})
</script>

<template>
  <div class="mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-1">{{ t('groupIndustry.contractSales.title') }}</h2>
    <p class="mb-4 text-xs text-slate-400">{{ t('groupIndustry.contractSales.hint') }}</p>

    <div v-if="store.loading && store.suggestions === null" class="flex items-center justify-center py-8">
      <LoadingSpinner size="lg" class="text-cyan-400" />
    </div>
    <p v-else-if="store.error" class="rounded-xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-400" role="alert">{{ store.error }}</p>

    <template v-else-if="store.suggestions !== null">
      <p
        v-if="store.suggestions.unreadableCharacters.length > 0"
        data-testid="contract-unreadable"
        :data-reasons="store.suggestions.unreadableCharacters.map(character => character.reason).join(',')"
        class="mb-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200"
      >{{ t('groupIndustry.contractSales.unreadable', { count: store.suggestions.unreadableCharacters.length }) }}</p>

      <p v-if="store.suggestions.candidates.length === 0" data-testid="contract-suggestions-empty" class="text-sm text-slate-400">{{ t('groupIndustry.contractSales.empty') }}</p>
      <ul v-else class="divide-y divide-slate-800 rounded-xl border border-slate-800 bg-slate-900/80">
        <li
          v-for="candidate in store.suggestions.candidates"
          :key="candidate.contractId"
          :data-testid="`contract-suggestion-${candidate.contractId}`"
          class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 text-sm"
        >
          <span class="min-w-0 flex-1 text-slate-200">
            <span class="font-medium">{{ formatNumber(candidate.quantity, 0) }} × {{ candidate.typeName }}</span>
            <span class="block text-xs text-slate-400">{{ candidate.sellerCharacterName }} · {{ formatDateTime(candidate.completedAt) }}</span>
          </span>
          <span class="font-mono text-slate-100">{{ formatIskExact(candidate.price) }}</span>
          <span class="font-mono text-xs text-slate-300">
            {{ t('groupIndustry.contractSales.fees') }}
            <span v-if="candidate.contractFees === null" data-testid="contract-fees-unknown"><UnknownValue /></span>
            <template v-else>-{{ formatIskExact(candidate.contractFees) }}</template>
          </span>
          <button
            type="button"
            :data-testid="`link-contract-${candidate.contractId}`"
            class="rounded-md border border-cyan-500/40 px-3 py-1.5 text-xs text-cyan-200 hover:bg-cyan-500/10 disabled:opacity-50"
            :disabled="recordingDisabled || linkingContractId !== null"
            @click="link(candidate)"
          >{{ t('groupIndustry.contractSales.link') }}</button>
        </li>
      </ul>
      <p v-if="linkError" data-testid="contract-link-error" class="mt-3 text-xs text-red-400" role="alert">{{ linkError }}</p>
    </template>
  </div>
</template>
