<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import MiningClosingLedger from '@/components/mining-operation/MiningClosingLedger.vue'
import MiningCorrectionModal from '@/components/mining-operation/MiningCorrectionModal.vue'
import MiningInvitationLink from '@/components/mining-operation/MiningInvitationLink.vue'
import MiningMinedQuantities from '@/components/mining-operation/MiningMinedQuantities.vue'
import MiningOperationMembers from '@/components/mining-operation/MiningOperationMembers.vue'
import MiningStartModal from '@/components/mining-operation/MiningStartModal.vue'
import MiningStartSnapshots from '@/components/mining-operation/MiningStartSnapshots.vue'
import MiningStatusBadge from '@/components/mining-operation/MiningStatusBadge.vue'
import MiningStockpileCoverage from '@/components/mining-operation/MiningStockpileCoverage.vue'
import { useStockpileCoverage } from '@/composables/useStockpileCoverage'
import { useAuthStore } from '@/stores/auth'
import { useMiningOperationStore, type MiningOperationMember } from '@/stores/miningOperation'
import { useSyncStore } from '@/stores/sync'
import { formatEveClock, formatEveDateTime } from './miningOperationTime'

type PendingConfirmation = 'renew' | 'delete' | 'end' | 'depart' | null

const MEMBER_ACTIONS_RELOADING_THE_OPERATION = [
  'member_joined',
  'member_updated',
  'member_removed',
  'join_code_renewed',
  'operation_started',
  'operation_ended',
  'member_departed',
  'quantity_corrected',
]
const SNAPSHOT_SYNC_TYPE = 'mining-operation-snapshot'
const CLOCK_TICK_MILLISECONDS = 1000

const { t } = useI18n()
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

function openCorrection(): void {
  correctionFailure.value = null
  correctionModalOpen.value = true
}

watch(
  () => syncStore.getSyncProgress(SNAPSHOT_SYNC_TYPE),
  (progress) => {
    if (progress?.data?.operationId === operationId.value) {
      store.fetchLedger(operationId.value)
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
    }
  },
)

onMounted(async () => {
  store.currentOperation = null
  store.members = []
  store.ledger = null
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

        <div v-if="operation.status === 'closing' && operationLedger !== null" class="grid gap-5 lg:grid-cols-3">
          <div class="space-y-5 lg:col-span-2">
            <MiningClosingLedger :ledger="operationLedger" :now="now" @retry="retrySnapshot" />
            <MiningMinedQuantities :ledger="operationLedger" />
          </div>
          <div class="space-y-5">
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
