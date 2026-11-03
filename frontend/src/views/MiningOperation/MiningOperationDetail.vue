<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import MiningClosingLedger from '@/components/mining-operation/MiningClosingLedger.vue'
import MiningCorrectionModal from '@/components/mining-operation/MiningCorrectionModal.vue'
import MiningFreezeShares from '@/components/mining-operation/MiningFreezeShares.vue'
import MiningInvitationLink from '@/components/mining-operation/MiningInvitationLink.vue'
import MiningMinedQuantities from '@/components/mining-operation/MiningMinedQuantities.vue'
import MiningOperationMembers from '@/components/mining-operation/MiningOperationMembers.vue'
import MiningOperationStepper from '@/components/mining-operation/MiningOperationStepper.vue'
import MiningShareTable from '@/components/mining-operation/MiningShareTable.vue'
import MiningStartModal from '@/components/mining-operation/MiningStartModal.vue'
import MiningStartSnapshots from '@/components/mining-operation/MiningStartSnapshots.vue'
import MiningStatusBadge from '@/components/mining-operation/MiningStatusBadge.vue'
import MiningStockpileCoverage from '@/components/mining-operation/MiningStockpileCoverage.vue'
import { useStockpileCoverage } from '@/composables/useStockpileCoverage'
import MiningUnitPrices from '@/components/mining-operation/MiningUnitPrices.vue'
import MiningValuationRules from '@/components/mining-operation/MiningValuationRules.vue'
import MiningValuationSettings from '@/components/mining-operation/MiningValuationSettings.vue'
import { useAuthStore } from '@/stores/auth'
import { useMiningOperationStore, type MiningOperationMember, type UpdateMiningValuationInput } from '@/stores/miningOperation'
import { useSyncStore } from '@/stores/sync'
import { formatWhole } from './miningOperationQuantity'
import { formatEveClock, formatEveDateTime } from './miningOperationTime'
import { missingTypeNames, unknownParticipantNames } from './miningOperationValuation'

type PendingConfirmation = 'renew' | 'delete' | 'end' | 'depart' | 'freeze' | null

const MEMBER_ACTIONS_RELOADING_THE_OPERATION = [
  'member_joined',
  'member_updated',
  'member_removed',
  'join_code_renewed',
  'operation_started',
  'operation_ended',
  'member_departed',
  'quantity_corrected',
  'shares_frozen',
]
const VALUATION_ACTION = 'valuation_updated'
const FULL_PERCENT = 100
const SNAPSHOT_SYNC_TYPE = 'mining-operation-snapshot'
const CLOCK_TICK_MILLISECONDS = 1000

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useMiningOperationStore()
const authStore = useAuthStore()
const { coverage: stockpileCoverage, hasError: stockpileCoverageFailed, load: loadStockpileCoverage } = useStockpileCoverage()
const syncStore = useSyncStore()

const operationId = computed(() => String(route.params.id))
const operation = computed(() => store.currentOperation)
const loading = ref(true)
const actionFailure = ref<string | null>(null)
const confirmation = ref<PendingConfirmation>(null)
const confirming = ref(false)
const startModalOpen = ref(false)
const correctionModalOpen = ref(false)
const correctionFailure = ref<string | null>(null)
const submitting = ref(false)
const savingSettings = ref(false)
const now = ref(Date.now())
let clockTicker: ReturnType<typeof setInterval> | null = null

const participates = computed(() => operation.value?.myStatus === 'accepted')
const isOwner = computed(() => operation.value?.myRole === 'owner')
const canAdminister = computed(() => participates.value && (isOwner.value || operation.value?.myRole === 'admin'))
const acceptsParticipants = computed(() => operation.value?.status === 'planned' || operation.value?.status === 'in_progress')
const canLeave = computed(() => {
  const current = operation.value
  if (current === null || isOwner.value) return false
  if (current.myStatus === 'pending') return current.status !== 'completed'
  return current.myStatus === 'accepted' && current.status === 'planned'
})
const canDelete = computed(() => isOwner.value && operation.value?.status === 'planned')
const canStart = computed(() => canAdminister.value && operation.value?.status === 'planned')
const canEnd = computed(() => canAdminister.value && operation.value?.status === 'in_progress')
const canDepart = computed(() => participates.value && operation.value?.status === 'in_progress' && operation.value.myDepartedAt === null)
const showsStockpileCoverage = computed(() => participates.value && (operation.value?.status === 'closing' || operation.value?.status === 'completed'))
const valuation = computed(() => (store.valuation?.operationId === operationId.value ? store.valuation : null))
const eachForThemselves = computed(() => operation.value?.splitMode === 'each_for_themselves')
const rulesSettings = computed(() => valuation.value?.settings ?? operation.value?.valuationSettings ?? null)
const marketLabel = computed(() => {
  const settings = rulesSettings.value
  return settings?.valuationMarket === 'structure' ? (settings.structureName ?? t('miningOperation.valuation.market.structure')) : t('miningOperation.valuation.market.jita')
})
const ownMemberId = computed(() => store.members.find(member => member.characters.some(character => ownCharacterIds.value.includes(character.id)))?.id ?? null)
const unknownCauses = computed(() => {
  const current = valuation.value
  if (current === null) return ''
  const causes: string[] = []
  const names = unknownParticipantNames(current, t('miningOperation.unknownPilot'))
  if (names !== '') causes.push(t('miningOperation.freeze.blockers.quantities_not_known', { names }))
  const types = missingTypeNames(current)
  if (types !== '') causes.push(t('miningOperation.freeze.blockers.prices_missing', { types }))
  return causes.join(' ; ')
})
const sharesFrozenKpis = computed(() => {
  const current = valuation.value
  const settings = current?.settings
  if (current === null || settings === undefined) return []
  const toShare = current.participants.reduce<number | null>((sum, participant) => (sum === null || participant.share === null ? null : sum + participant.share), 0)
  return [
    { label: t('miningOperation.shares.kpi.total'), value: current.totalValue },
    { label: t('miningOperation.shares.kpi.booster', { percent: settings.boosterCutPercent }), value: current.boosterCut },
    { label: t('miningOperation.shares.kpi.toShare', { percent: FULL_PERCENT - settings.boosterCutPercent - settings.corporationCutPercent }), value: toShare },
    { label: t('miningOperation.shares.kpi.iskPerHour'), value: current.iskPerHour },
  ]
})
const operationLedger = computed(() => (store.ledger?.operationId === operationId.value ? store.ledger : null))
const departures = computed(() =>
  (operationLedger.value?.participants ?? []).flatMap(participant =>
    participant.departedAt === null
      ? []
      : [{ memberId: participant.memberId, name: participant.characterName ?? t('miningOperation.unknownPilot'), time: formatEveClock(participant.departedAt) }],
  ),
)
const runningMeta = computed(() => {
  const current = operation.value
  if (current === null || current.startedAt === null || current.status === 'planned') return null
  return t('miningOperation.running.meta', {
    system: current.solarSystemName ?? t('miningOperation.start.systemNone'),
    time: formatEveClock(current.startedAt),
  })
})
const endSubmitLabel = computed(() => t('miningOperation.end.submit', { time: formatEveClock(new Date(now.value).toISOString()) }))
const ownCharacterIds = computed(() => (authStore.user?.characters ?? []).map(character => character.id))
const ownerName = computed(() => operation.value?.ownerCharacterName ?? t('miningOperation.unknownPilot'))

async function load(): Promise<void> {
  await store.fetchDetail(operationId.value)
  if (participates.value) {
    await store.fetchMembers(operationId.value)
    if (operation.value?.status !== 'planned') {
      await store.fetchLedger(operationId.value)
    }
    if (showsStockpileCoverage.value) {
      await loadStockpileCoverage(operationId.value)
    }
    await store.fetchValuation(operationId.value)
  }
}

async function runAction(action: () => Promise<unknown>): Promise<void> {
  actionFailure.value = null
  try {
    await action()
  } catch (cause) {
    actionFailure.value = cause instanceof Error ? cause.message : String(cause)
  }
}

function goBackToList(notice?: 'deleted'): void {
  router.push({ name: 'mining-operations', ...(notice ? { query: { notice } } : {}) })
}

async function confirmPending(): Promise<void> {
  const pending = confirmation.value
  confirming.value = true
  await runAction(async () => {
    if (pending === 'renew') {
      await store.renewJoinCode(operationId.value)
    } else if (pending === 'delete') {
      await store.deleteOperation(operationId.value)
      goBackToList('deleted')
    } else if (pending === 'end') {
      await store.end(operationId.value)
      await load()
    } else if (pending === 'depart') {
      await store.depart(operationId.value)
      await load()
    } else if (pending === 'freeze') {
      await store.freezeShares(operationId.value)
      await load()
    }
  })
  confirming.value = false
  confirmation.value = null
}

const acceptMember = (member: MiningOperationMember) =>
  runAction(async () => {
    await store.updateMember(operationId.value, member.id, { status: 'accepted' })
    await load()
  })

const changeRole = (member: MiningOperationMember, role: 'admin' | 'member') =>
  runAction(() => store.updateMember(operationId.value, member.id, { role }))

const removeMember = (member: MiningOperationMember) =>
  runAction(async () => {
    await store.removeMember(operationId.value, member.id)
    await load()
  })

const leaveOperation = () =>
  runAction(async () => {
    await store.leave(operationId.value)
    goBackToList()
  })

async function startOperation(solarSystemId: number | null): Promise<void> {
  submitting.value = true
  await runAction(async () => {
    await store.start(operationId.value, solarSystemId)
    startModalOpen.value = false
    await load()
  })
  submitting.value = false
}

const retrySnapshot = (operationCharacterId: string) =>
  runAction(async () => {
    await store.retrySnapshot(operationId.value, operationCharacterId)
    await store.fetchLedger(operationId.value)
  })

async function correctQuantity(operationCharacterId: string, typeId: number, quantity: number): Promise<void> {
  submitting.value = true
  correctionFailure.value = null
  try {
    await store.correctQuantity(operationId.value, operationCharacterId, typeId, quantity)
    correctionModalOpen.value = false
    await store.fetchLedger(operationId.value)
  } catch (cause) {
    correctionFailure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    submitting.value = false
  }
}

async function saveSettings(patch: UpdateMiningValuationInput): Promise<void> {
  savingSettings.value = true
  await runAction(() => store.updateValuation(operationId.value, patch))
  savingSettings.value = false
}

const rereadPrices = () => runAction(() => store.fetchValuation(operationId.value))

function amountText(amount: number | null): string {
  return amount === null ? t('miningOperation.quantities.unknown') : formatWhole(amount, locale.value)
}

function openCorrection(): void {
  correctionFailure.value = null
  correctionModalOpen.value = true
}

watch(
  () => syncStore.getSyncProgress(SNAPSHOT_SYNC_TYPE),
  (progress) => {
    if (progress?.data?.operationId === operationId.value) {
      store.fetchLedger(operationId.value)
      store.fetchValuation(operationId.value)
    }
  },
)

watch(
  () => syncStore.getMiningOperationEvent(operationId.value),
  (event) => {
    if (event === null) return
    if (event.action === 'operation_deleted') {
      goBackToList('deleted')
    } else if (MEMBER_ACTIONS_RELOADING_THE_OPERATION.includes(event.action)) {
      load()
    } else if (event.action === VALUATION_ACTION) {
      store.fetchValuation(operationId.value)
    }
  },
)

onMounted(async () => {
  store.currentOperation = null
  store.members = []
  store.ledger = null
  store.valuation = null
  clockTicker = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MILLISECONDS)
  await load()
  loading.value = false
})

onBeforeUnmount(() => {
  if (clockTicker !== null) clearInterval(clockTicker)
})
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-5xl px-6 py-8">
      <div v-if="loading" class="flex justify-center py-16">
        <LoadingSpinner size="xl" class="text-cyan-400" />
      </div>

      <p v-else-if="operation === null" class="eve-card p-8 text-center text-sm text-red-400" role="alert">{{ store.error }}</p>

      <div v-else class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h1 class="truncate font-display text-2xl font-bold text-slate-100" :title="operation.label">{{ operation.label }}</h1>
            <p class="mt-1 truncate text-sm text-slate-400" :title="ownerName">{{ ownerName }}</p>
            <p v-if="runningMeta !== null" class="mt-1 truncate font-mono text-xs text-cyan-200" :title="runningMeta">{{ runningMeta }}</p>
          </div>
          <div class="flex items-center gap-2">
            <MiningStatusBadge :status="operation.status" />
            <span class="shrink-0 rounded-sm border border-slate-600 bg-slate-800 px-2 py-0.5 font-mono text-xs text-slate-300">{{ t(`miningOperation.visibility.${operation.visibility}`) }}</span>
          </div>
        </header>

        <MiningOperationStepper :status="operation.status" :split-mode="operation.splitMode" />

        <p v-if="actionFailure !== null" class="text-sm text-red-400" role="alert">{{ actionFailure }}</p>

        <MiningInvitationLink
          v-if="operation.joinCode !== null"
          :join-code="operation.joinCode"
          :can-renew="canAdminister && acceptsParticipants"
          @renew="confirmation = 'renew'"
        />

        <section v-if="participates" class="eve-card p-5">
          <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.detail.participants') }}</h2>
            <p class="font-mono text-xs text-slate-400">{{ t('miningOperation.detail.summary', { participants: operation.participantsCount, characters: operation.charactersCount }) }}</p>
          </div>
          <MiningOperationMembers
            :members="store.members"
            :own-character-ids="ownCharacterIds"
            :can-administer="canAdminister"
            :is-owner="isOwner"
            @accept="acceptMember"
            @remove="removeMember"
            @promote="member => changeRole(member, 'admin')"
            @demote="member => changeRole(member, 'member')"
          />
          <div v-if="operation.status === 'in_progress' && operationLedger !== null" class="mt-3 border-t border-slate-800 pt-1">
            <MiningStartSnapshots :participants="operationLedger.participants" @retry="retrySnapshot" />
          </div>
        </section>

        <MiningValuationSettings
          v-if="valuation !== null && participates && acceptsParticipants"
          :settings="valuation.settings"
          :split-mode="operation.splitMode"
          :members="store.members"
          :editable="canAdminister"
          :corporation-known="operation.corporationId !== null"
          :saving="savingSettings"
          @save="saveSettings"
        />

        <div v-if="operation.status === 'closing'" class="grid gap-5 lg:grid-cols-3">
          <div class="space-y-5 lg:col-span-2">
            <template v-if="valuation !== null">
              <p v-if="eachForThemselves" class="rounded-sm border border-cyan-500/30 bg-cyan-500/5 px-3 py-1.5 text-xs text-cyan-200">{{ t('miningOperation.shares.eachForThemselvesBanner') }}</p>
              <p v-if="valuation.totalValue === null" class="rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200" role="alert">{{ t('miningOperation.shares.unknownBanner', { causes: unknownCauses }) }}</p>
              <MiningShareTable
                :valuation="valuation"
                :title="eachForThemselves ? t('miningOperation.shares.valueTitle') : t('miningOperation.shares.previewTitle')"
                :show-shares="!eachForThemselves"
                :own-member-id="ownMemberId"
                :market-label="marketLabel"
                @reread="rereadPrices"
              />
            </template>
            <template v-if="operationLedger !== null">
              <MiningClosingLedger :ledger="operationLedger" :now="now" @retry="retrySnapshot" />
              <MiningMinedQuantities :ledger="operationLedger" />
            </template>
          </div>
          <div class="space-y-5">
            <MiningValuationSettings
              v-if="valuation !== null && participates"
              :settings="valuation.settings"
              :split-mode="operation.splitMode"
              :members="store.members"
              :editable="canAdminister"
              :corporation-known="operation.corporationId !== null"
              :saving="savingSettings"
              @save="saveSettings"
            />
            <MiningFreezeShares v-if="valuation !== null && canAdminister && !eachForThemselves" :valuation="valuation" @freeze="confirmation = 'freeze'" />
            <MiningUnitPrices v-if="valuation !== null" :title="t('miningOperation.shares.unitPricesRead')" :unit-prices="valuation.unitPrices" />
            <template v-if="operationLedger !== null">
              <section class="eve-card p-5">
                <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.closing.departures') }}</h2>
                <p v-if="departures.length === 0" class="text-sm text-slate-400">{{ t('miningOperation.closing.noDeparture') }}</p>
                <p v-for="departure in departures" :key="departure.memberId" class="text-sm text-slate-300">{{ t('miningOperation.closing.departed', { name: departure.name, time: departure.time }) }}</p>
              </section>
              <section class="eve-card p-5">
                <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.correction.title') }}</h2>
                <p class="mb-3 text-sm text-slate-300">{{ t('miningOperation.correction.card') }}</p>
                <button v-if="canAdminister" type="button" class="rounded-md border border-cyan-500/40 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/10" @click="openCorrection">{{ t('miningOperation.correction.action') }}</button>
              </section>
            </template>
          </div>
        </div>

        <div v-else-if="operation.status === 'shares' && valuation !== null" class="space-y-5">
          <p v-if="operation.sharesFrozenAt !== null" class="rounded-sm border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200" role="status">{{ t('miningOperation.freeze.frozenBanner', { date: formatEveDateTime(operation.sharesFrozenAt) }) }}</p>
          <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="kpi in sharesFrozenKpis" :key="kpi.label" class="eve-card p-4">
              <dt class="truncate text-xs uppercase tracking-wider text-slate-400" :title="kpi.label">{{ kpi.label }}</dt>
              <dd class="mt-1 font-mono text-lg" :class="kpi.value === null ? 'text-amber-300' : 'text-cyan-200'">{{ amountText(kpi.value) }}</dd>
            </div>
          </dl>
          <div class="grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
              <MiningShareTable
                :valuation="valuation"
                :title="t('miningOperation.shares.title')"
                :show-shares="true"
                :own-member-id="ownMemberId"
                :market-label="marketLabel"
                @reread="rereadPrices"
              />
            </div>
            <MiningUnitPrices :title="t('miningOperation.shares.unitPricesFrozen')" :unit-prices="valuation.unitPrices" />
          </div>
        </div>

        <template v-if="showsStockpileCoverage">
          <MiningStockpileCoverage v-if="stockpileCoverage !== null" :coverage="stockpileCoverage" />
          <p v-else-if="stockpileCoverageFailed" class="text-sm text-red-400" role="alert">{{ t('miningOperation.stockpileCoverage.error') }}</p>
        </template>

        <div class="grid gap-5 md:grid-cols-2">
          <section class="eve-card p-5">
            <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.detail.start') }}</h2>
            <p class="font-mono text-lg text-cyan-200">{{ formatEveDateTime(operation.plannedStartAt) }} EVE</p>
            <p v-if="operation.status === 'planned'" class="mt-2 text-xs text-slate-400">{{ t('miningOperation.start.hint') }}</p>
          </section>
          <section class="eve-card p-5">
            <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.detail.rules') }}</h2>
            <p class="text-sm text-slate-200"><span class="text-slate-400">{{ t('miningOperation.detail.split') }} :</span> {{ t(`miningOperation.splitMode.${operation.splitMode}`) }}</p>
            <MiningValuationRules v-if="rulesSettings !== null" class="mt-2" :settings="rulesSettings" :split-mode="operation.splitMode" />
          </section>
        </div>

        <div class="flex flex-wrap gap-3">
          <button v-if="canStart" type="button" class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500" @click="startModalOpen = true">{{ t('miningOperation.start.action') }}</button>
          <button v-if="canEnd" type="button" class="rounded-md border border-amber-500/40 px-4 py-2 text-sm text-amber-200 hover:bg-amber-500/10" @click="confirmation = 'end'">{{ t('miningOperation.end.action') }}</button>
          <button v-if="canDepart" type="button" class="rounded-md border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800" @click="confirmation = 'depart'">{{ t('miningOperation.depart.action') }}</button>
          <button v-if="canLeave" type="button" class="rounded-md border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800" @click="leaveOperation">{{ t('miningOperation.detail.leave') }}</button>
          <button v-if="canDelete" type="button" class="rounded-md border border-red-500/40 px-4 py-2 text-sm text-red-300 hover:bg-red-500/10" @click="confirmation = 'delete'">{{ t('miningOperation.detail.delete') }}</button>
        </div>
      </div>
    </div>

    <ConfirmModal
      :show="confirmation === 'renew'"
      :title="t('miningOperation.invite.renew')"
      :message="t('miningOperation.invite.renewConfirm')"
      :is-loading="confirming"
      confirm-color="cyan"
      icon="check"
      @confirm="confirmPending"
      @cancel="confirmation = null"
    />
    <ConfirmModal
      :show="confirmation === 'end'"
      :title="t('miningOperation.end.title')"
      :message="t('miningOperation.end.hint')"
      :confirm-label="endSubmitLabel"
      :is-loading="confirming"
      confirm-color="cyan"
      icon="check"
      @confirm="confirmPending"
      @cancel="confirmation = null"
    />
    <ConfirmModal
      :show="confirmation === 'freeze'"
      :title="t('miningOperation.freeze.title')"
      :message="t('miningOperation.freeze.confirm')"
      :confirm-label="t('miningOperation.freeze.action')"
      :is-loading="confirming"
      confirm-color="cyan"
      icon="check"
      @confirm="confirmPending"
      @cancel="confirmation = null"
    />
    <ConfirmModal
      :show="confirmation === 'depart'"
      :title="t('miningOperation.depart.action')"
      :message="t('miningOperation.depart.confirm')"
      :confirm-label="t('miningOperation.depart.action')"
      :is-loading="confirming"
      confirm-color="cyan"
      icon="check"
      @confirm="confirmPending"
      @cancel="confirmation = null"
    />
    <MiningStartModal
      :show="startModalOpen"
      :label="operation?.label ?? ''"
      :submitting="submitting"
      @close="startModalOpen = false"
      @submit="startOperation"
    />
    <MiningCorrectionModal
      v-if="operationLedger !== null"
      :show="correctionModalOpen"
      :ledger="operationLedger"
      :submitting="submitting"
      :failure="correctionFailure"
      @close="correctionModalOpen = false"
      @submit="correctQuantity"
    />
    <ConfirmModal
      :show="confirmation === 'delete'"
      :title="t('miningOperation.detail.delete')"
      :message="t('miningOperation.detail.deleteConfirm')"
      :is-loading="confirming"
      @confirm="confirmPending"
      @cancel="confirmation = null"
    />
  </MainLayout>
</template>
