<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatStatementPeriod, groupByCorporation, statementKey, type MoonTaxMineStatement } from '@/composables/useMoonTax'
import { useAuthStore } from '@/stores/auth'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MoonTaxAmountCell from './MoonTaxAmountCell.vue'
import MoonTaxReferenceCell from './MoonTaxReferenceCell.vue'
import MoonTaxStatusCell from './MoonTaxStatusCell.vue'

const props = defineProps<{
  statements: MoonTaxMineStatement[]
  isLoading: boolean
  hasError: boolean
  structureLabel: (structureId: number) => string
  corporationLabel: (corporationId: number) => string
}>()

const COLUMNS = ['period', 'refinery', 'character', 'tax', 'paid', 'remaining', 'status', 'reference', 'actions']
const COPIED_FEEDBACK_MS = 2_000

const { t } = useI18n()
const authStore = useAuthStore()

const copiedReference = ref<string | null>(null)
const hasCopyFailed = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

const groups = computed(() => groupByCorporation(props.statements))

function accountCharacterName(characterId: number): string | null {
  return authStore.user?.characters.find(character => character.eveCharacterId === characterId)?.name ?? null
}

async function copyReference(reference: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(reference)
  } catch {
    hasCopyFailed.value = true
    return
  }
  hasCopyFailed.value = false
  copiedReference.value = reference
  if (copiedTimer !== null) clearTimeout(copiedTimer)
  copiedTimer = setTimeout(() => {
    copiedReference.value = null
  }, COPIED_FEEDBACK_MS)
}

onUnmounted(() => {
  if (copiedTimer !== null) clearTimeout(copiedTimer)
})
</script>

<template>
  <div class="space-y-4">
    <div data-how-to-pay class="space-y-1 rounded-xl border border-slate-800 bg-slate-900 p-4 text-sm text-slate-300">
      <h4 class="text-xs font-semibold uppercase tracking-wider text-cyan-300">{{ t('ledger.mining.moonTax.howToPay.title') }}</h4>
      <p>{{ t('ledger.mining.moonTax.howToPay.donation') }}</p>
      <p class="text-slate-400">{{ t('ledger.mining.moonTax.howToPay.recognition') }}</p>
      <p class="text-slate-400">{{ t('ledger.mining.moonTax.howToPay.alt') }}</p>
    </div>

    <ErrorBanner v-if="hasError" :message="t('ledger.mining.moonTax.mineLoadError')" />
    <ErrorBanner v-if="hasCopyFailed" :message="t('ledger.mining.moonTax.copyFailed')" @dismiss="hasCopyFailed = false" />

    <div v-if="isLoading && statements.length === 0" class="flex justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>
    <p v-else-if="!hasError && !isLoading && statements.length === 0" data-empty-mine class="text-sm text-slate-500">
      {{ t('ledger.mining.moonTax.emptyMine') }}
    </p>

    <section v-for="group in groups" :key="group.corporationId" :data-mine-corporation="group.corporationId" class="space-y-3">
      <h4 class="truncate text-sm font-semibold uppercase tracking-wider text-slate-300" :title="corporationLabel(group.corporationId)">
        {{ corporationLabel(group.corporationId) }}
      </h4>
      <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
        <table class="w-full">
          <thead class="bg-slate-900/50">
            <tr>
              <th
                v-for="column in COLUMNS"
                :key="column"
                scope="col"
                class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider whitespace-nowrap text-slate-400"
              >
                {{ t(`ledger.mining.moonTax.columns.${column}`) }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr v-for="statement in group.statements" :key="statementKey(statement)" data-mine-row class="transition-colors hover:bg-slate-900/30">
              <td data-cell="period" class="px-3 py-2 text-sm whitespace-nowrap text-slate-300">
                {{ formatStatementPeriod(statement) }}
              </td>
              <td data-cell="refinery" class="max-w-[18rem] px-3 py-2 text-sm text-slate-300">
                <span class="block truncate" :title="structureLabel(statement.structureId)">{{ structureLabel(statement.structureId) }}</span>
              </td>
              <td data-cell="character" class="max-w-[14rem] px-3 py-2 text-sm text-white">
                <span class="block truncate" :title="accountCharacterName(statement.characterId) ?? undefined">
                  <template v-if="accountCharacterName(statement.characterId) !== null">{{ accountCharacterName(statement.characterId) }} </template>#{{ statement.characterId }}
                </span>
              </td>
              <MoonTaxAmountCell data-cell="tax" :amount="statement.tax" :unknown-type-ids="statement.unknownTypeIds" />
              <MoonTaxAmountCell data-cell="paid" :amount="statement.paid" :unknown-type-ids="[]" />
              <MoonTaxAmountCell data-cell="remaining" :amount="statement.remaining" :unknown-type-ids="statement.unknownTypeIds" />
              <MoonTaxStatusCell data-cell="status" :status="statement.status" />
              <MoonTaxReferenceCell data-cell="reference" :reference="statement.reference" />
              <td data-cell="actions" class="px-3 py-2 whitespace-nowrap">
                <button
                  v-if="statement.reference !== null"
                  type="button"
                  data-copy-reference
                  class="rounded-sm border border-slate-700 px-2 py-0.5 text-xs font-medium uppercase tracking-wider text-slate-300 transition-colors hover:text-white"
                  @click="copyReference(statement.reference)"
                >
                  {{ copiedReference === statement.reference ? t('ledger.mining.moonTax.copied') : t('ledger.mining.moonTax.copy') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
