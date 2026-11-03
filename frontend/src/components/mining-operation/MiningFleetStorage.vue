<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import type {
  DeclareMiningFleetStorageInput,
  MiningFleetStorage,
  MiningFleetStorageDeclaration,
  MiningFleetStorageDelivery,
  MiningFleetStorageVerification,
  MiningOperationLedger,
  MiningOperationValuation,
  MiningValuationParticipant,
  UpdateMiningFleetStorageInput,
} from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import MiningFleetStorageVerificationBlock from './MiningFleetStorageVerification.vue'

const DELIVERIES: MiningFleetStorageDelivery[] = ['none', 'all', 'partial']
const DECIMAL_RADIX = 10

interface QuantityField {
  typeId: number
  typeName: string
  delivered: number
  mined: number | null
}

const props = defineProps<{
  fleetStorage: MiningFleetStorage
  valuation: MiningOperationValuation | null
  ledger: MiningOperationLedger | null
  verification: MiningFleetStorageVerification | null
  endedAt: string | null
  canAdminister: boolean
  refreshing: boolean
}>()

const emit = defineEmits<{
  update: [patch: UpdateMiningFleetStorageInput]
  declare: [memberId: string, declaration: DeclareMiningFleetStorageInput]
  refresh: []
}>()

const { t, locale } = useI18n()
const recipientFieldId = useId()
const containerFieldId = useId()

const partialEditing = ref<string[]>([])
const quantityDrafts = ref<Record<string, Record<number, number>>>({})

const canEdit = computed(() => props.canAdminister && props.fleetStorage.editable)
const recipientName = computed(() => props.fleetStorage.recipientName ?? t('miningOperation.unknownPilot'))
const depositors = computed(() => props.fleetStorage.declarations.filter(declaration => declaration.memberId !== props.fleetStorage.recipientMemberId))
const unknownQuantityNames = computed(() =>
  (props.valuation?.participants ?? [])
    .filter(participant => participant.fleetStorageDelivery === 'all' && participant.quantityState !== 'known')
    .map(participant => participant.characterName ?? t('miningOperation.unknownPilot'))
    .join(', '),
)
const missingTotal = computed(() => (props.verification?.lines ?? []).reduce((sum, line) => (line.missingQuantity === null ? sum : sum + line.missingQuantity), 0))
const summaryBadge = computed(() => {
  const status = props.verification?.status
  if (status === 'verified') return { text: t('miningOperation.fleetStorage.verification.status.verified'), tone: 'emerald' }
  if (status === 'short') return { text: t('miningOperation.fleetStorage.verification.status.short', { missing: formatWhole(missingTotal.value, locale.value) }), tone: 'amber' }
  if (status === 'unknown') return { text: t('miningOperation.fleetStorage.verification.status.unknown'), tone: 'amber' }
  return null
})
const hold = computed(() => {
  const valuation = props.valuation
  if (valuation === null || props.fleetStorage.recipientMemberId === null) return null
  const recipient = valuation.participants.find(participant => participant.isFleetStorageRecipient)
  if (recipient === undefined) return null
  const others = valuation.participants.filter(participant => !participant.isFleetStorageRecipient)
  const deliveredValues = others.flatMap(participant => (participant.deliveredValue === null ? [] : [participant.deliveredValue]))
  const delivered = deliveredValues.length === others.length ? deliveredValues.reduce((sum, value) => sum + value, 0) : null
  const own = recipient.keptValue
  return { own, delivered, total: own === null || delivered === null ? null : own + delivered }
})

watch(
  () => props.fleetStorage,
  () => {
    partialEditing.value = []
    quantityDrafts.value = {}
  },
)

function nameOf(declaration: MiningFleetStorageDeclaration): string {
  return declaration.characterName ?? t('miningOperation.unknownPilot')
}

function valuedOf(declaration: MiningFleetStorageDeclaration): MiningValuationParticipant | null {
  return props.valuation?.participants.find(participant => participant.memberId === declaration.memberId) ?? null
}

function pressedOf(declaration: MiningFleetStorageDeclaration): MiningFleetStorageDelivery {
  return partialEditing.value.includes(declaration.memberId) ? 'partial' : declaration.delivery
}

function canPress(declaration: MiningFleetStorageDeclaration): boolean {
  return props.fleetStorage.editable && declaration.canDeclare
}

function choose(declaration: MiningFleetStorageDeclaration, delivery: MiningFleetStorageDelivery): void {
  if (delivery === 'partial') {
    if (declaration.delivery !== 'partial' && !partialEditing.value.includes(declaration.memberId)) {
      partialEditing.value = [...partialEditing.value, declaration.memberId]
    }
    return
  }
  partialEditing.value = partialEditing.value.filter(memberId => memberId !== declaration.memberId)
  emit('declare', declaration.memberId, { delivery })
}

function deliveredQuantitiesOf(declaration: MiningFleetStorageDeclaration): Record<number, number> {
  const declared = Object.fromEntries(declaration.quantities.map(line => [line.typeId, line.quantity]))
  return { ...declared, ...quantityDrafts.value[declaration.memberId] }
}

function fieldsOf(declaration: MiningFleetStorageDeclaration): QuantityField[] {
  const ledgerParticipant = props.ledger?.participants.find(participant => participant.memberId === declaration.memberId)
  const delivered = deliveredQuantitiesOf(declaration)
  const types = new Map<number, string>()
  for (const line of ledgerParticipant?.quantities ?? []) types.set(line.typeId, line.typeName ?? String(line.typeId))
  for (const line of declaration.quantities) if (!types.has(line.typeId)) types.set(line.typeId, line.typeName ?? String(line.typeId))
  return [...types].map(([typeId, typeName]) => ({
    typeId,
    typeName,
    delivered: delivered[typeId] ?? 0,
    mined: ledgerParticipant?.state === 'known' ? (ledgerParticipant.quantities.find(line => line.typeId === typeId)?.quantity ?? 0) : null,
  }))
}

function exceeds(field: QuantityField): boolean {
  return field.mined !== null && field.delivered > field.mined
}

function changeQuantity(declaration: MiningFleetStorageDeclaration, typeId: number, event: Event): void {
  const typed = Number.parseInt((event.target as HTMLInputElement).value, DECIMAL_RADIX)
  const quantities = { ...deliveredQuantitiesOf(declaration), [typeId]: Number.isNaN(typed) || typed < 0 ? 0 : typed }
  quantityDrafts.value = { ...quantityDrafts.value, [declaration.memberId]: quantities }
  const lines = Object.entries(quantities)
    .map(([id, quantity]) => ({ typeId: Number(id), quantity }))
    .filter(line => line.quantity > 0)
  if (lines.length > 0) emit('declare', declaration.memberId, { delivery: 'partial', quantities: lines })
}

function changeRecipient(event: Event): void {
  const memberId = (event.target as HTMLSelectElement).value
  emit('update', { recipientMemberId: memberId === '' ? null : memberId })
}

function changeContainer(event: Event): void {
  const typed = (event.target as HTMLInputElement).value.trim()
  const containerName = typed === '' ? null : typed
  if (containerName !== props.fleetStorage.containerName) emit('update', { containerName })
}

function debtOf(declaration: MiningFleetStorageDeclaration): { debt: number | null; share: number | null; kept: number | null } {
  const valued = valuedOf(declaration)
  return { debt: valued?.fleetStorageDebt ?? null, share: valued?.share ?? null, kept: valued?.keptValue ?? null }
}

function amountOf(value: number): string {
  return formatWhole(value, locale.value)
}
</script>

<template>
  <section class="eve-card p-5">
    <div class="mb-1 flex items-center justify-between gap-2">
      <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.fleetStorage.title') }}</h2>
      <span
        v-if="summaryBadge !== null"
        class="shrink-0 rounded-sm border px-2 py-0.5 font-mono text-xs"
        :class="summaryBadge.tone === 'emerald' ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-300'"
      >{{ summaryBadge.text }}</span>
    </div>
    <p class="mb-3 text-xs text-slate-400">{{ t('miningOperation.fleetStorage.rule') }}</p>

    <template v-if="canEdit">
      <div class="mt-3 flex flex-col gap-1.5">
        <label :for="recipientFieldId" class="text-xs text-slate-400">{{ t('miningOperation.fleetStorage.recipient') }}</label>
        <select
          :id="recipientFieldId"
          class="rounded-md border border-slate-700 bg-slate-950 px-2.5 py-2 text-sm text-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-400"
          :value="fleetStorage.recipientMemberId ?? ''"
          @change="changeRecipient"
        >
          <option value="">{{ t('miningOperation.fleetStorage.noRecipient') }}</option>
          <option v-for="declaration in fleetStorage.declarations" :key="declaration.memberId" :value="declaration.memberId">{{ nameOf(declaration) }}</option>
        </select>
      </div>
      <div class="mt-3 flex flex-col gap-1.5">
        <label :for="containerFieldId" class="text-xs text-slate-400">{{ t('miningOperation.fleetStorage.container') }}</label>
        <input
          :id="containerFieldId"
          type="text"
          maxlength="100"
          class="rounded-md border border-slate-700 bg-slate-950 px-2.5 py-2 text-sm text-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-400"
          :value="fleetStorage.containerName ?? ''"
          @change="changeContainer"
        />
        <p class="text-xs text-slate-500">{{ t('miningOperation.fleetStorage.containerHint') }}</p>
      </div>
    </template>
    <dl v-else class="space-y-1 text-sm">
      <div class="flex flex-wrap gap-x-2">
        <dt class="text-slate-400">{{ t('miningOperation.fleetStorage.recipient') }} :</dt>
        <dd class="min-w-0 truncate text-slate-200" :title="fleetStorage.recipientName ?? t('miningOperation.fleetStorage.noRecipient')">{{ fleetStorage.recipientName ?? t('miningOperation.fleetStorage.noRecipient') }}</dd>
      </div>
      <div v-if="fleetStorage.containerName !== null" class="flex flex-wrap gap-x-2">
        <dt class="text-slate-400">{{ t('miningOperation.fleetStorage.container') }} :</dt>
        <dd class="min-w-0 truncate text-slate-200" :title="fleetStorage.containerName">{{ fleetStorage.containerName }}</dd>
      </div>
    </dl>

    <div class="mt-4 flex flex-col">
      <div
        v-for="declaration in depositors"
        :key="declaration.memberId"
        class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 border-l-2 py-2.5 pl-4"
        :class="pressedOf(declaration) === 'none' ? 'border-slate-700' : 'border-cyan-500'"
      >
        <span class="min-w-0 truncate text-sm font-semibold text-slate-100" :title="nameOf(declaration)">{{ nameOf(declaration) }}</span>
        <div role="group" :aria-label="nameOf(declaration)" class="inline-flex overflow-hidden rounded-md border border-slate-700">
          <button
            v-for="delivery in DELIVERIES"
            :key="delivery"
            type="button"
            class="border-slate-700 px-2.5 py-1 text-xs text-slate-400 first:border-r-0 disabled:cursor-not-allowed disabled:opacity-50 aria-pressed:bg-cyan-500/15 aria-pressed:text-cyan-300 [&:not(:first-child)]:border-l"
            :aria-pressed="pressedOf(declaration) === delivery"
            :disabled="!canPress(declaration)"
            @click="choose(declaration, delivery)"
          >{{ t(`miningOperation.fleetStorage.delivery.${delivery}`) }}</button>
        </div>

        <div v-if="pressedOf(declaration) === 'partial'" class="col-span-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
          <div v-for="field in fieldsOf(declaration)" :key="field.typeId" class="flex flex-wrap items-center gap-x-2">
            <span class="max-w-32 truncate" :title="field.typeName">{{ field.typeName }}</span>
            <i18n-t keypath="miningOperation.fleetStorage.quantity" scope="global" tag="span" class="flex items-center gap-1">
              <template #delivered>
                <input
                  type="number"
                  min="0"
                  class="w-28 rounded-md border bg-slate-950 px-2 py-1 text-right font-mono text-sm disabled:cursor-not-allowed disabled:opacity-50"
                  :class="exceeds(field) ? 'border-amber-500/60 text-amber-200' : 'border-slate-700 text-slate-100'"
                  :value="field.delivered"
                  :disabled="!canPress(declaration)"
                  :aria-label="field.typeName"
                  @change="changeQuantity(declaration, field.typeId, $event)"
                />
              </template>
              <template #mined>{{ field.mined === null ? '' : amountOf(field.mined) }}</template>
            </i18n-t>
            <span v-if="field.mined === null" class="text-amber-300"><UnknownValue /></span>
            <span v-else-if="exceeds(field)" class="text-amber-300">{{ t('miningOperation.fleetStorage.exceedsMined', { mined: amountOf(field.mined) }) }}</span>
          </div>
        </div>

        <p v-if="declaration.delivery === 'none'" class="col-span-2 text-xs text-slate-400">{{ t('miningOperation.fleetStorage.keeps') }}</p>
        <template v-else>
        <div v-for="row in [debtOf(declaration)]" :key="row.debt ?? 'unknown'" class="col-span-2 font-mono text-sm text-violet-300">
          <i18n-t keypath="miningOperation.fleetStorage.debt" scope="global" tag="p">
            <template #recipient><span class="inline-block max-w-40 truncate align-bottom" :title="recipientName">{{ recipientName }}</span></template>
            <template #amount>
              <UnknownValue v-if="row.debt === null" />
              <template v-else>{{ amountOf(row.debt) }}</template>
            </template>
          </i18n-t>
          <p v-if="row.share !== null && row.kept !== null" class="text-xs text-slate-400">
            {{ t('miningOperation.fleetStorage.debtDetail', { share: amountOf(row.share), kept: amountOf(row.kept) }) }}
          </p>
        </div>
        </template>
      </div>

      <div v-if="hold !== null" class="mt-2 rounded-lg border border-cyan-500/40 bg-cyan-500/5 px-3.5 py-3">
        <p class="text-xs text-cyan-300">{{ t('miningOperation.fleetStorage.hold') }}</p>
        <div class="flex items-center justify-between gap-2">
          <span class="min-w-0 truncate text-sm text-slate-100" :title="recipientName">{{ recipientName }}</span>
          <span class="shrink-0 font-mono text-sm text-slate-100"><UnknownValue v-if="hold.total === null" /><template v-else>{{ amountOf(hold.total) }} ISK</template></span>
        </div>
        <p v-if="hold.own !== null && hold.delivered !== null" class="text-xs text-slate-400">{{ t('miningOperation.fleetStorage.holdDetail', { own: amountOf(hold.own), delivered: amountOf(hold.delivered) }) }}</p>
      </div>
    </div>

    <MiningFleetStorageVerificationBlock
      v-if="verification !== null"
      :verification="verification"
      :ended-at="endedAt"
      :unknown-quantity-names="unknownQuantityNames"
      :refreshing="refreshing"
      @refresh="emit('refresh')"
    />
  </section>
</template>
