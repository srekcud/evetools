<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatStatementPeriod, NON_CANCELLABLE_STATUSES, PAYABLE_STATUSES, statementKey, type MoonTaxStatement } from '@/composables/useMoonTax'
import MoonTaxAmountCell from './MoonTaxAmountCell.vue'
import MoonTaxReferenceCell from './MoonTaxReferenceCell.vue'
import MoonTaxStatusCell from './MoonTaxStatusCell.vue'

defineProps<{
  statements: MoonTaxStatement[]
  structureLabel: (structureId: number) => string
  busyStatementId: string | null
  rowErrors: Record<string, string>
}>()

const emit = defineEmits<{
  markPaid: [statementId: string]
  cancel: [statementId: string]
}>()

const COLUMNS = ['character', 'refinery', 'period', 'reference', 'tax', 'paid', 'remaining', 'status', 'outside', 'actions']
const ACTION_BUTTON_CLASSES = 'rounded-sm border px-2 py-0.5 text-xs font-medium uppercase tracking-wider transition-colors disabled:cursor-not-allowed disabled:opacity-40'

const { t } = useI18n()

const confirmingCancelId = ref<string | null>(null)

function characterLabel(statement: MoonTaxStatement): string {
  return statement.characterName ?? `#${statement.characterId}`
}

function confirmCancel(statementId: string): void {
  confirmingCancelId.value = null
  emit('cancel', statementId)
}
</script>

<template>
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
        <template v-for="statement in statements" :key="statementKey(statement)">
          <tr data-statement-row class="transition-colors hover:bg-slate-900/30">
            <td data-cell="character" class="max-w-[14rem] px-3 py-2 text-sm text-white">
              <span class="block truncate" :title="characterLabel(statement)">{{ characterLabel(statement) }}</span>
            </td>
            <td data-cell="refinery" class="max-w-[18rem] px-3 py-2 text-sm text-slate-300">
              <span class="block truncate" :title="structureLabel(statement.structureId)">{{ structureLabel(statement.structureId) }}</span>
            </td>
            <td data-cell="period" class="px-3 py-2 text-sm whitespace-nowrap text-slate-300">
              {{ formatStatementPeriod(statement) }}
            </td>
            <MoonTaxReferenceCell data-cell="reference" :reference="statement.reference" />
            <MoonTaxAmountCell data-cell="tax" :amount="statement.tax" :unknown-type-ids="statement.unknownTypeIds" />
            <MoonTaxAmountCell data-cell="paid" :amount="statement.paid" :unknown-type-ids="[]" />
            <MoonTaxAmountCell data-cell="remaining" :amount="statement.remaining" :unknown-type-ids="statement.unknownTypeIds" />
            <MoonTaxStatusCell data-cell="status" :status="statement.status" />
            <td data-cell="outside" class="px-3 py-2 text-xs uppercase tracking-wider whitespace-nowrap text-amber-400">
              <template v-if="statement.outsideAlliance === true">{{ t('ledger.mining.moonTax.outsideAlliance') }}</template>
            </td>
            <td data-cell="actions" class="px-3 py-2 whitespace-nowrap">
              <div v-if="statement.id !== null" class="flex flex-wrap items-center gap-2">
                <button
                  v-if="PAYABLE_STATUSES.includes(statement.status)"
                  type="button"
                  data-action="mark-paid"
                  :disabled="busyStatementId === statement.id"
                  :class="[ACTION_BUTTON_CLASSES, 'border-emerald-500/60 text-emerald-300 hover:bg-emerald-500/10']"
                  @click="emit('markPaid', statement.id)"
                >
                  {{ t('ledger.mining.moonTax.actions.markPaid') }}
                </button>
                <template v-if="!NON_CANCELLABLE_STATUSES.includes(statement.status)">
                  <template v-if="confirmingCancelId === statement.id">
                    <button
                      type="button"
                      data-action="confirm-cancel"
                      :disabled="busyStatementId === statement.id"
                      :class="[ACTION_BUTTON_CLASSES, 'border-red-500/60 bg-red-500/10 text-red-300 hover:bg-red-500/20']"
                      @click="confirmCancel(statement.id)"
                    >
                      {{ t('ledger.mining.moonTax.actions.confirmCancel') }}
                    </button>
                    <button
                      type="button"
                      data-action="keep"
                      :class="[ACTION_BUTTON_CLASSES, 'border-slate-700 text-slate-400 hover:text-slate-200']"
                      @click="confirmingCancelId = null"
                    >
                      {{ t('ledger.mining.moonTax.actions.keep') }}
                    </button>
                  </template>
                  <button
                    v-else
                    type="button"
                    data-action="cancel"
                    :disabled="busyStatementId === statement.id"
                    :class="[ACTION_BUTTON_CLASSES, 'border-slate-700 text-slate-400 hover:text-red-300']"
                    @click="confirmingCancelId = statement.id"
                  >
                    {{ t('ledger.mining.moonTax.actions.cancel') }}
                  </button>
                </template>
              </div>
            </td>
          </tr>
          <tr v-if="statement.id !== null && rowErrors[statement.id] !== undefined">
            <td :colspan="COLUMNS.length" class="px-3 py-2">
              <p data-row-error role="alert" class="text-sm text-red-400">{{ rowErrors[statement.id] }}</p>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
