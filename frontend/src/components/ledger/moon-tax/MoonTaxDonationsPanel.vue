<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatChunkTime } from '@/composables/useCorporationMiningExtractions'
import { useFormatters } from '@/composables/useFormatters'
import { useMoonTaxDonations } from '@/composables/useMoonTaxDonations'
import { withoutKey, type MoonTaxDonationEntry, type MoonTaxStatement } from '@/composables/useMoonTax'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

interface MinerOption {
  characterId: number
  label: string
}

const props = defineProps<{
  corporationId: number
  statements: MoonTaxStatement[]
}>()

const emit = defineEmits<{
  attributed: []
}>()

const EVE_TIME_ZONE = 'UTC'
const FILTERS = ['unmatched', 'all'] as const
const TOGGLE_CLASSES = 'px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors'
const ACTIVE_TOGGLE_CLASSES = 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300'
const IDLE_TOGGLE_CLASSES = 'border-slate-700 text-slate-400 hover:text-slate-200'
const COLUMNS = ['date', 'donor', 'amount', 'reason', 'attribution', 'actions']

type DonationFilter = typeof FILTERS[number]

const { t } = useI18n()
const { formatIsk } = useFormatters()
const { donations, isLoading, hasLoadError, busyJournalId, rowErrors, load, attribute } = useMoonTaxDonations(props.corporationId)

const filter = ref<DonationFilter>('unmatched')
const chosenMiners = ref<Record<number, number>>({})

const miners = computed<MinerOption[]>(() => {
  const names = new Map<number, string>()
  for (const statement of props.statements) {
    names.set(statement.characterId, statement.characterName ?? names.get(statement.characterId) ?? `#${statement.characterId}`)
  }
  return Array.from(names, ([characterId, label]) => ({ characterId, label })).sort((first, second) => first.label.localeCompare(second.label))
})

const visibleDonations = computed<MoonTaxDonationEntry[]>(() =>
  filter.value === 'all' ? donations.value : donations.value.filter(donation => !donation.payerIsMiner),
)

function toggleClasses(active: boolean): string[] {
  return [TOGGLE_CLASSES, active ? ACTIVE_TOGGLE_CLASSES : IDLE_TOGGLE_CLASSES]
}

function donorLabel(donation: MoonTaxDonationEntry): string {
  return donation.firstPartyName ?? `#${donation.firstPartyId}`
}

function attributedLabel(donation: MoonTaxDonationEntry): string {
  return donation.attributedCharacterName ?? `#${donation.attributedCharacterId}`
}

function chosenMiner(donation: MoonTaxDonationEntry): number | null {
  return chosenMiners.value[donation.journalId] ?? donation.attributedCharacterId
}

function chooseMiner(journalId: number, value: string): void {
  const others = withoutKey(chosenMiners.value, journalId)
  chosenMiners.value = value === '' ? others : { ...others, [journalId]: Number(value) }
}

async function attributeTo(donation: MoonTaxDonationEntry): Promise<void> {
  const characterId = chosenMiner(donation)
  if (characterId === null) return
  if (await attribute(donation.journalId, characterId)) emit('attributed')
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div data-donations class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('ledger.mining.moonTax.donations.title') }}</h5>
      <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.moonTax.donations.filterLabel')">
        <button
          v-for="candidate in FILTERS"
          :key="candidate"
          type="button"
          :data-donation-filter="candidate"
          :aria-pressed="filter === candidate"
          :class="toggleClasses(filter === candidate)"
          @click="filter = candidate"
        >
          {{ t(`ledger.mining.moonTax.donations.filters.${candidate}`) }}
        </button>
      </div>
    </div>

    <ErrorBanner v-if="hasLoadError" data-donations-error :message="t('ledger.mining.moonTax.donations.loadError')" />

    <div v-if="isLoading && donations.length === 0 && !hasLoadError" class="flex justify-center py-6">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <p
      v-else-if="!hasLoadError && !isLoading && visibleDonations.length === 0"
      data-donations-empty
      class="text-sm text-slate-500"
    >
      {{ t(filter === 'all' ? 'ledger.mining.moonTax.donations.emptyAll' : 'ledger.mining.moonTax.donations.emptyUnmatched') }}
    </p>

    <div v-if="visibleDonations.length > 0" class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
      <table class="w-full">
        <thead class="bg-slate-900/50">
          <tr>
            <th
              v-for="column in COLUMNS"
              :key="column"
              scope="col"
              class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider whitespace-nowrap text-slate-400"
            >
              {{ t(`ledger.mining.moonTax.donations.columns.${column}`) }}
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr
            v-for="donation in visibleDonations"
            :key="donation.journalId"
            data-donation-row
            :data-journal-id="donation.journalId"
            class="transition-colors hover:bg-slate-900/30"
          >
            <td class="px-3 py-2 text-sm whitespace-nowrap text-slate-300">
              <time :datetime="donation.date">{{ formatChunkTime(donation.date, EVE_TIME_ZONE) }}</time>
            </td>
            <td class="max-w-[14rem] px-3 py-2 text-sm text-white">
              <span class="block truncate" :title="donorLabel(donation)">{{ donorLabel(donation) }}</span>
            </td>
            <td class="px-3 py-2 text-right text-sm whitespace-nowrap text-slate-200">{{ formatIsk(donation.amount) }}</td>
            <td class="max-w-[16rem] px-3 py-2 text-sm text-slate-300">
              <span class="block truncate" :title="donation.reason ?? undefined">{{ donation.reason ?? t('ledger.mining.moonTax.donations.noReason') }}</span>
            </td>
            <td class="px-3 py-2 text-sm">
              <template v-if="donation.attributedCharacterId !== null && donation.attributedAt !== null">
                <span class="block max-w-[14rem] truncate text-emerald-300" :title="attributedLabel(donation)">{{ attributedLabel(donation) }}</span>
                <span class="block text-xs whitespace-nowrap text-slate-500">
                  {{ t('ledger.mining.moonTax.donations.attributedOn', { date: formatChunkTime(donation.attributedAt, EVE_TIME_ZONE) }) }}
                </span>
              </template>
              <span v-else class="text-amber-400">{{ t('ledger.mining.moonTax.donations.notAttributed') }}</span>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">
              <div class="flex flex-wrap items-center gap-2">
                <select
                  data-attribute-select
                  :value="chosenMiner(donation) ?? ''"
                  :aria-label="t('ledger.mining.moonTax.donations.chooseMiner')"
                  class="max-w-[12rem] rounded-sm border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-200 focus:border-cyan-500/60 focus:outline-none"
                  @change="chooseMiner(donation.journalId, ($event.target as HTMLSelectElement).value)"
                >
                  <option value="">{{ t('ledger.mining.moonTax.donations.chooseMiner') }}</option>
                  <option v-for="miner in miners" :key="miner.characterId" :value="miner.characterId">{{ miner.label }}</option>
                </select>
                <button
                  type="button"
                  data-action="attribute"
                  :disabled="chosenMiner(donation) === null || busyJournalId === donation.journalId"
                  class="rounded-sm border border-cyan-500/60 px-2 py-0.5 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:opacity-40"
                  @click="attributeTo(donation)"
                >
                  {{ t('ledger.mining.moonTax.donations.attribute') }}
                </button>
              </div>
              <p v-if="rowErrors[donation.journalId] !== undefined" data-donation-error role="alert" class="mt-1 max-w-[18rem] text-sm whitespace-normal text-red-400">
                {{ rowErrors[donation.journalId] }}
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
