<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import MiningInvitationLink from '@/components/mining-operation/MiningInvitationLink.vue'
import MiningOperationMembers from '@/components/mining-operation/MiningOperationMembers.vue'
import MiningStatusBadge from '@/components/mining-operation/MiningStatusBadge.vue'
import { useAuthStore } from '@/stores/auth'
import { useMiningOperationStore, type MiningOperationMember } from '@/stores/miningOperation'
import { useSyncStore } from '@/stores/sync'
import { formatEveDateTime } from './miningOperationTime'

type PendingConfirmation = 'renew' | 'delete' | null

const MEMBER_ACTIONS_RELOADING_THE_OPERATION = ['member_joined', 'member_updated', 'member_removed', 'join_code_renewed']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useMiningOperationStore()
const authStore = useAuthStore()
const syncStore = useSyncStore()

const operationId = computed(() => String(route.params.id))
const operation = computed(() => store.currentOperation)
const loading = ref(true)
const actionFailure = ref<string | null>(null)
const confirmation = ref<PendingConfirmation>(null)
const confirming = ref(false)

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
const ownCharacterIds = computed(() => (authStore.user?.characters ?? []).map(character => character.id))
const ownerName = computed(() => operation.value?.ownerCharacterName ?? t('miningOperation.unknownPilot'))

async function load(): Promise<void> {
  await store.fetchDetail(operationId.value)
  if (participates.value) {
    await store.fetchMembers(operationId.value)
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
  await load()
  loading.value = false
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
        </section>

        <div class="grid gap-5 md:grid-cols-2">
          <section class="eve-card p-5">
            <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.detail.start') }}</h2>
            <p class="font-mono text-lg text-cyan-200">{{ formatEveDateTime(operation.plannedStartAt) }} EVE</p>
          </section>
          <section class="eve-card p-5">
            <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.detail.rules') }}</h2>
            <p class="text-sm text-slate-200"><span class="text-slate-400">{{ t('miningOperation.detail.split') }} :</span> {{ t(`miningOperation.splitMode.${operation.splitMode}`) }}</p>
          </section>
        </div>

        <div class="flex flex-wrap gap-3">
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
      :show="confirmation === 'delete'"
      :title="t('miningOperation.detail.delete')"
      :message="t('miningOperation.detail.deleteConfirm')"
      :is-loading="confirming"
      @confirm="confirmPending"
      @cancel="confirmation = null"
    />
  </MainLayout>
</template>
