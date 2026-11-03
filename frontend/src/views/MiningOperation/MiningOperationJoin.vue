<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningCharacterPicker from '@/components/mining-operation/MiningCharacterPicker.vue'
import MiningValuationRules from '@/components/mining-operation/MiningValuationRules.vue'
import { ApiError } from '@/services/api'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { formatEveDateTime } from './miningOperationTime'

type JoinState = 'loading' | 'invalidLink' | 'failed' | 'alreadyMember' | 'pending' | 'closed' | 'form'

const NOT_FOUND_STATUS = 404

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useMiningOperationStore()

const joinCode = computed(() => String(route.params.code))
const loading = ref(true)
const invalidLink = ref(false)
const loadFailure = ref<string | null>(null)
const joinFailure = ref<string | null>(null)
const submitting = ref(false)
const tickedCharacterIds = ref<string[]>([])

const invitation = computed(() => store.invitation)

const state = computed<JoinState>(() => {
  if (loading.value) return 'loading'
  if (invalidLink.value) return 'invalidLink'
  const current = invitation.value
  if (loadFailure.value !== null || current === null) return 'failed'
  if (current.myStatus === 'accepted') return 'alreadyMember'
  if (current.myStatus === 'pending') return 'pending'
  if (current.status !== 'planned' && current.status !== 'in_progress') return 'closed'
  return 'form'
})

const ownerName = computed(() => invitation.value?.ownerCharacterName ?? t('miningOperation.unknownPilot'))

async function loadInvitation(): Promise<void> {
  loading.value = true
  invalidLink.value = false
  loadFailure.value = null
  try {
    await store.fetchInvitation(joinCode.value)
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === NOT_FOUND_STATUS) {
      invalidLink.value = true
    } else {
      loadFailure.value = cause instanceof Error ? cause.message : String(cause)
    }
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  submitting.value = true
  joinFailure.value = null
  try {
    const joined = await store.join(joinCode.value, tickedCharacterIds.value)
    if (joined?.myStatus === 'accepted') {
      router.push({ name: 'mining-operation-detail', params: { id: joined.operationId } })
    }
  } catch (cause) {
    joinFailure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    submitting.value = false
  }
}

onMounted(loadInvitation)
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-2xl px-6 py-12">
      <div v-if="state === 'loading'" class="flex justify-center py-16">
        <LoadingSpinner size="xl" class="text-cyan-400" />
      </div>

      <div v-else-if="state === 'invalidLink'" class="eve-card p-8 text-center" role="alert">
        <p class="font-display text-lg font-semibold text-slate-100">{{ t('miningOperation.join.invalidLink') }}</p>
      </div>

      <div v-else-if="state === 'failed'" class="eve-card p-8 text-center" role="alert">
        <p class="text-sm text-red-400">{{ loadFailure }}</p>
      </div>

      <div v-else-if="invitation !== null" class="space-y-5">
        <header>
          <h1 class="font-display text-2xl font-bold text-slate-100" :title="invitation.label">
            <span class="block truncate">{{ t('miningOperation.join.title', { label: invitation.label }) }}</span>
          </h1>
          <p class="mt-1 truncate font-mono text-sm text-slate-400">
            {{ t('miningOperation.join.subtitle', { owner: ownerName, start: formatEveDateTime(invitation.plannedStartAt) }) }}
          </p>
        </header>

        <div v-if="state === 'alreadyMember'" class="eve-card p-6">
          <p class="mb-4 text-slate-200">{{ t('miningOperation.join.alreadyMember') }}</p>
          <router-link
            :to="{ name: 'mining-operation-detail', params: { id: invitation.operationId } }"
            class="inline-block rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500"
          >{{ t('miningOperation.list.open') }}</router-link>
        </div>

        <div v-else-if="state === 'pending'" class="eve-card border-cyan-500/40 p-6" role="status">
          <h2 class="font-display text-lg font-semibold text-cyan-300">{{ t('miningOperation.join.pendingTitle') }}</h2>
          <p class="mt-1 text-sm text-slate-300">{{ t('miningOperation.join.pendingDescription') }}</p>
        </div>

        <div v-else-if="state === 'closed'" class="eve-card p-6" role="status">
          <p class="text-slate-200">{{ t('miningOperation.join.closed') }}</p>
        </div>

        <template v-else>
          <section class="eve-card p-5">
            <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.join.whichCharacters') }}</h2>
            <MiningCharacterPicker v-model="tickedCharacterIds" :characters="invitation.characters" />
            <p class="mt-3 text-xs text-slate-400">{{ t('miningOperation.join.oneOpenOperation') }}</p>
          </section>

          <section class="eve-card p-5">
            <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.join.rules') }}</h2>
            <p class="text-sm text-slate-200">{{ t(`miningOperation.splitMode.${invitation.splitMode}`) }}</p>
            <MiningValuationRules class="mt-2" :settings="invitation.valuationSettings" :split-mode="invitation.splitMode" />
            <p class="mt-2 text-xs text-slate-400">
              {{ invitation.autoAccepted ? t('miningOperation.join.autoAccepted') : t('miningOperation.join.needsApproval') }}
            </p>
          </section>

          <p v-if="joinFailure !== null" class="text-sm text-red-400" role="alert">{{ joinFailure }}</p>

          <button
            type="button"
            class="w-full rounded-md bg-cyan-600 px-4 py-3 font-display text-base font-semibold text-white transition-colors hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="tickedCharacterIds.length === 0 || submitting"
            @click="submit"
          >{{ t('miningOperation.join.submit', tickedCharacterIds.length) }}</button>
        </template>
      </div>
    </div>
  </MainLayout>
</template>
