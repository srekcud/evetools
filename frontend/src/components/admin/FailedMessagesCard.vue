<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { ApiError, apiRequest } from '@/services/api'

interface FailedMessageClassCount {
  messageClass: string
  count: number
}

interface FailedMessageRow {
  id: string
  messageClass: string | null
  exceptionClass: string | null
  exceptionMessage: string | null
  failedAt: string | null
  retryCount: number | null
}

interface FailedMessageListing {
  total: number
  classes: FailedMessageClassCount[]
  messages: FailedMessageRow[]
}

type PendingRemoval =
  | { kind: 'message' }
  | { kind: 'class'; count: number }

type Removal = PendingRemoval & { target: string; label: string }

const FAILED_MESSAGES_PATH = '/admin/failed-messages'
const NOT_FOUND_STATUS = 404
const UNKNOWN_VALUE = '—'

const props = defineProps<{
  refreshSignal?: string | null
}>()

const { t, locale } = useI18n()

const listing = ref<FailedMessageListing | null>(null)
const loadFailed = ref(false)
const actionFailed = ref(false)
const busy = ref(false)
const pendingRemoval = ref<Removal | null>(null)

const hasMoreThanListed = computed(() => listing.value !== null && listing.value.messages.length < listing.value.total)

const removalTitle = computed(() => {
  if (!pendingRemoval.value) return ''
  return pendingRemoval.value.kind === 'class'
    ? t('admin.failedMessages.confirm.removeClass.title')
    : t('admin.failedMessages.confirm.removeMessage.title')
})

const removalMessage = computed(() => {
  const removal = pendingRemoval.value
  if (!removal) return ''
  if (removal.kind === 'class') {
    return t('admin.failedMessages.confirm.removeClass.message', { count: removal.count, messageClass: removal.label }, removal.count)
  }
  return t('admin.failedMessages.confirm.removeMessage.message')
})

function shortClassName(messageClass: string | null): string {
  if (messageClass === null) return UNKNOWN_VALUE
  return messageClass.slice(messageClass.lastIndexOf('\\') + 1)
}

function formatFailedAt(failedAt: string | null): string {
  if (failedAt === null) return UNKNOWN_VALUE
  return new Date(failedAt).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' })
}

async function load(): Promise<void> {
  try {
    listing.value = await apiRequest<FailedMessageListing>(FAILED_MESSAGES_PATH)
    loadFailed.value = false
  } catch {
    listing.value = null
    loadFailed.value = true
  }
}

async function runAction(action: () => Promise<unknown>): Promise<void> {
  busy.value = true
  actionFailed.value = false
  try {
    await action()
  } catch (error) {
    actionFailed.value = !(error instanceof ApiError && error.status === NOT_FOUND_STATUS)
  }
  await load()
  busy.value = false
}

function postClassAction(action: 'retry-class' | 'remove-class', messageClass: string): Promise<unknown> {
  return apiRequest(`${FAILED_MESSAGES_PATH}/${action}`, { method: 'POST', body: JSON.stringify({ messageClass }) })
}

function retryMessage(id: string): Promise<void> {
  return runAction(() => apiRequest(`${FAILED_MESSAGES_PATH}/${encodeURIComponent(id)}/retry`, { method: 'POST' }))
}

function retryClass(messageClass: string): Promise<void> {
  return runAction(() => postClassAction('retry-class', messageClass))
}

function askMessageRemoval(message: FailedMessageRow): void {
  pendingRemoval.value = { kind: 'message', target: message.id, label: shortClassName(message.messageClass) }
}

function askClassRemoval(failedClass: FailedMessageClassCount): void {
  pendingRemoval.value = { kind: 'class', count: failedClass.count, target: failedClass.messageClass, label: shortClassName(failedClass.messageClass) }
}

async function confirmRemoval(): Promise<void> {
  const removal = pendingRemoval.value
  if (!removal) return
  pendingRemoval.value = null
  await runAction(() =>
    removal.kind === 'class'
      ? postClassAction('remove-class', removal.target)
      : apiRequest(`${FAILED_MESSAGES_PATH}/${encodeURIComponent(removal.target)}`, { method: 'DELETE' }),
  )
}

function cancelRemoval(): void {
  pendingRemoval.value = null
}

onMounted(load)

watch(() => props.refreshSignal, (signal) => {
  if (signal) void load()
})
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.failedMessages.title') }}</h3>
      <span
        v-if="listing"
        data-testid="failed-messages-total"
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', listing.total > 0 ? 'bg-red-500/20 text-red-400' : 'bg-emerald-500/20 text-emerald-400']"
      >{{ listing.total }}</span>
    </div>

    <p v-if="actionFailed" data-testid="failed-messages-action-error" class="mb-4 text-sm text-red-400">{{ t('admin.failedMessages.actionError') }}</p>

    <p v-if="loadFailed" data-testid="failed-messages-load-error" class="text-sm text-red-400">{{ t('admin.failedMessages.loadError') }}</p>

    <LoadingSpinner v-else-if="!listing" size="sm" />

    <p v-else-if="listing.total === 0" data-testid="failed-messages-empty" class="text-sm text-slate-500">{{ t('admin.failedMessages.empty') }}</p>

    <template v-else>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 mb-6">
        <div
          v-for="failedClass in listing.classes"
          :key="failedClass.messageClass"
          data-testid="failed-message-class"
          class="rounded-lg border border-slate-700 bg-slate-800/50 p-3"
        >
          <div class="flex items-center justify-between gap-2 mb-3">
            <span class="font-mono text-sm text-slate-200 truncate" :title="failedClass.messageClass">{{ shortClassName(failedClass.messageClass) }}</span>
            <span
              data-testid="failed-message-class-count"
              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-400"
            >{{ failedClass.count }}</span>
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              data-testid="failed-message-class-retry"
              :disabled="busy"
              class="flex-1 px-2.5 py-1.5 bg-orange-500/20 hover:bg-orange-500/30 text-orange-400 rounded-lg text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              @click="retryClass(failedClass.messageClass)"
            >{{ t('admin.failedMessages.retryClass') }}</button>
            <button
              type="button"
              data-testid="failed-message-class-remove"
              :disabled="busy"
              class="flex-1 px-2.5 py-1.5 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              @click="askClassRemoval(failedClass)"
            >{{ t('admin.failedMessages.removeClass') }}</button>
          </div>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-slate-500 text-xs uppercase">
              <th class="text-left pb-2 font-medium">{{ t('admin.failedMessages.columns.messageClass') }}</th>
              <th class="text-left pb-2 font-medium">{{ t('admin.failedMessages.columns.exception') }}</th>
              <th class="text-left pb-2 font-medium">{{ t('admin.failedMessages.columns.message') }}</th>
              <th class="text-left pb-2 font-medium">{{ t('admin.failedMessages.columns.failedAt') }}</th>
              <th class="text-right pb-2 font-medium">{{ t('admin.failedMessages.columns.retryCount') }}</th>
              <th class="text-right pb-2 font-medium">{{ t('admin.failedMessages.columns.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr v-for="message in listing.messages" :key="message.id" data-testid="failed-message-row">
              <td class="py-1.5 pr-3 font-mono text-xs text-slate-300 whitespace-nowrap" :title="message.messageClass ?? undefined">{{ shortClassName(message.messageClass) }}</td>
              <td class="py-1.5 pr-3 font-mono text-xs text-slate-400 break-all">{{ message.exceptionClass ?? UNKNOWN_VALUE }}</td>
              <td class="py-1.5 pr-3 text-xs text-slate-400 break-words max-w-md">{{ message.exceptionMessage ?? UNKNOWN_VALUE }}</td>
              <td class="py-1.5 pr-3 text-xs text-slate-400 whitespace-nowrap">{{ formatFailedAt(message.failedAt) }}</td>
              <td data-testid="failed-message-retry-count" class="py-1.5 pr-3 text-right text-slate-300">{{ message.retryCount ?? UNKNOWN_VALUE }}</td>
              <td class="py-1.5 text-right whitespace-nowrap">
                <button
                  type="button"
                  data-testid="failed-message-retry"
                  :disabled="busy"
                  class="px-2.5 py-1 mr-2 bg-orange-500/20 hover:bg-orange-500/30 text-orange-400 rounded-lg text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  @click="retryMessage(message.id)"
                >{{ t('admin.failedMessages.retry') }}</button>
                <button
                  type="button"
                  data-testid="failed-message-remove"
                  :disabled="busy"
                  class="px-2.5 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  @click="askMessageRemoval(message)"
                >{{ t('admin.failedMessages.remove') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="hasMoreThanListed" class="mt-3 text-xs text-slate-500">
        {{ t('admin.failedMessages.showingRecent', { shown: listing.messages.length, total: listing.total }) }}
      </p>
    </template>

    <ConfirmModal
      :show="pendingRemoval !== null"
      :title="removalTitle"
      :subtitle="removalMessage"
      @confirm="confirmRemoval"
      @cancel="cancelRemoval"
    />
  </div>
</template>
