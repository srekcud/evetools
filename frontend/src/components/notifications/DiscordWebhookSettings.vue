<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDiscordWebhookStore } from '@/stores/discordWebhook'

type TestOutcome = 'success' | 'failure'

const { t } = useI18n()
const store = useDiscordWebhookStore()

const url = ref('')
const testOutcome = ref<TestOutcome | null>(null)

const isConfigured = computed(() => store.configured === true)
const canSave = computed(() => url.value.trim() !== '' && !store.isLoading)

async function saveWebhook(): Promise<void> {
  testOutcome.value = null
  if (await store.save(url.value.trim())) {
    url.value = ''
  }
}

async function removeWebhook(): Promise<void> {
  testOutcome.value = null
  await store.remove()
}

async function sendTestMessage(): Promise<void> {
  testOutcome.value = null
  testOutcome.value = (await store.sendTest()) ? 'success' : 'failure'
}

onMounted(async () => {
  store.clearError()
  await store.fetchStatus()
})
</script>

<template>
  <section class="mt-6 pt-4 border-t border-slate-800" data-testid="discord-webhook-settings">
    <div class="flex items-center justify-between gap-3 mb-1">
      <h3 class="text-xs text-slate-500 uppercase tracking-wider font-medium">{{ t('notificationHub.discord.title') }}</h3>
      <span
        data-testid="discord-webhook-status"
        :data-configured="isConfigured"
        :class="[
          'px-2 py-0.5 rounded-md text-xs border',
          isConfigured
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
            : 'bg-slate-800 border-slate-700 text-slate-400'
        ]"
      >
        {{ isConfigured ? t('notificationHub.discord.configured') : t('notificationHub.discord.notConfigured') }}
      </span>
    </div>
    <p class="text-xs text-slate-500 mb-3">{{ t('notificationHub.discord.help') }}</p>

    <p
      v-if="store.error && testOutcome !== 'failure'"
      role="alert"
      class="mb-3 px-3 py-2 bg-red-500/10 border border-red-500/30 rounded-lg text-xs text-red-400"
      data-testid="discord-webhook-error"
    >
      {{ store.error }}
    </p>

    <label class="block text-xs text-slate-400 mb-1" for="discord-webhook-url">{{ t('notificationHub.discord.urlLabel') }}</label>
    <div class="flex flex-col sm:flex-row gap-2">
      <input
        id="discord-webhook-url"
        v-model="url"
        type="password"
        autocomplete="off"
        spellcheck="false"
        data-testid="discord-webhook-url-input"
        :class="[
          'flex-1 min-w-0 px-3 py-2 text-sm bg-slate-800 border rounded-lg text-slate-200 focus:outline-none',
          store.fieldError ? 'border-red-500/50 focus:border-red-500/70' : 'border-slate-700 focus:border-cyan-500/50'
        ]"
      />
      <button
        type="button"
        data-testid="discord-webhook-save"
        :disabled="!canSave"
        class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors"
        @click="saveWebhook"
      >
        {{ t('notificationHub.discord.save') }}
      </button>
    </div>
    <p v-if="store.fieldError" class="mt-1 text-xs text-red-400" data-testid="discord-webhook-field-error">{{ store.fieldError }}</p>

    <div v-if="isConfigured" class="mt-3 flex flex-wrap items-center gap-2">
      <button
        type="button"
        data-testid="discord-webhook-test"
        :disabled="store.isLoading"
        class="px-3 py-1.5 text-sm text-slate-300 hover:text-white hover:bg-slate-800 border border-slate-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors"
        @click="sendTestMessage"
      >
        {{ t('notificationHub.discord.test') }}
      </button>
      <button
        type="button"
        data-testid="discord-webhook-remove"
        :disabled="store.isLoading"
        class="px-3 py-1.5 text-sm text-red-400 hover:bg-red-500/10 border border-red-500/30 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors"
        @click="removeWebhook"
      >
        {{ t('notificationHub.discord.remove') }}
      </button>
      <span v-if="testOutcome === 'success'" class="text-xs text-emerald-400" data-testid="discord-webhook-test-success">
        {{ t('notificationHub.discord.testSucceeded') }}
      </span>
      <span v-else-if="testOutcome === 'failure'" class="text-xs text-red-400" data-testid="discord-webhook-test-failure">
        {{ t('notificationHub.discord.testFailed') }}<template v-if="store.error">: {{ store.error }}</template>
      </span>
    </div>
  </section>
</template>
