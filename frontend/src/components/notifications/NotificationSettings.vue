<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotificationsStore } from '@/stores/notifications'
import type { NotificationPreference, NotificationCategory } from '@/stores/notifications'
import { usePushNotifications } from '@/composables/usePushNotifications'
import { useToast } from '@/composables/useToast'
import { categoryIcons, categoryColors } from './notificationConstants'

const { t } = useI18n()
const store = useNotificationsStore()
const push = usePushNotifications()
const { showToast } = useToast()

const emit = defineEmits<{
  close: []
}>()

const localPreferences = ref<NotificationPreference[]>([])
const isSaving = ref(false)
const pushDenied = ref(false)

// Categories that support threshold configuration
const thresholdCategories: NotificationCategory[] = ['planetary', 'escalation']

function hasThreshold(category: NotificationCategory): boolean {
  return thresholdCategories.includes(category)
}

async function handlePushToggle(pref: NotificationPreference): Promise<void> {
  if (pref.pushEnabled) {
    // Turning push ON: check permission
    if (!push.isSupported.value) {
      pref.pushEnabled = false
      return
    }

    if (push.permission.value === 'denied') {
      pref.pushEnabled = false
      pushDenied.value = true
      return
    }

    if (push.permission.value !== 'granted') {
      const granted = await push.requestPermission()
      if (!granted) {
        pref.pushEnabled = false
        pushDenied.value = true
        return
      }
    }

    pushDenied.value = false
  }
}

async function save(): Promise<void> {
  isSaving.value = true
  try {
    await store.savePreferences(localPreferences.value)

    // If any push is enabled, register subscription; otherwise unregister
    const anyPushEnabled = localPreferences.value.some(p => p.pushEnabled)
    if (anyPushEnabled) {
      try {
        await push.subscribe()
      } catch (e) {
        console.error('Failed to subscribe to push:', e)
      }
    }

    showToast(t('common.actions.save'), 'success')
    emit('close')
  } catch {
    showToast(t('common.errors.saveFailed'), 'error')
  } finally {
    isSaving.value = false
  }
}

onMounted(async () => {
  await store.fetchPreferences()
  // Deep clone to avoid mutating store state directly
  localPreferences.value = store.preferences.map(p => ({ ...p }))
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-center justify-center">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-slate-950/80" @click="emit('close')"></div>

      <!-- Modal -->
      <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-lg mx-4 max-h-[85vh] flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800 shrink-0">
          <h3 class="text-lg font-semibold text-slate-100">{{ t('notificationHub.settingsModal.title') }}</h3>
          <button
            @click="emit('close')"
            class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          >
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="p-6 overflow-y-auto min-h-0">
          <!-- Push not supported warning -->
          <div v-if="!push.isSupported.value" class="mb-4 px-3 py-2 bg-amber-500/10 border border-amber-500/30 rounded-lg text-xs text-amber-400">
            {{ t('notificationHub.settingsModal.pushNotSupported') }}
          </div>

          <!-- Push denied warning -->
          <div v-if="pushDenied" class="mb-4 px-3 py-2 bg-red-500/10 border border-red-500/30 rounded-lg text-xs text-red-400">
            {{ t('notificationHub.settingsModal.pushDenied') }}
          </div>

          <!-- Table header -->
          <div class="grid grid-cols-12 gap-2 px-3 py-2 text-xs text-slate-500 uppercase tracking-wider font-medium">
            <div class="col-span-4">{{ t('notificationHub.settingsModal.category') }}</div>
            <div class="col-span-2 text-center">{{ t('notificationHub.settingsModal.enabled') }}</div>
            <div class="col-span-3 text-center">{{ t('notificationHub.settingsModal.push') }}</div>
            <div class="col-span-3 text-center">{{ t('notificationHub.settingsModal.threshold') }}</div>
          </div>

          <!-- Category rows -->
          <div class="space-y-1">
            <div
              v-for="pref in localPreferences"
              :key="pref.category"
              class="grid grid-cols-12 gap-2 items-center px-3 py-3 rounded-lg hover:bg-slate-800/30 transition-colors"
            >
              <!-- Category name with icon -->
              <div class="col-span-4 flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" :class="categoryColors[pref.category]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="categoryIcons[pref.category]" />
                </svg>
                <span class="text-sm text-slate-200">{{ t(`notificationHub.categories.${pref.category}`) }}</span>
              </div>

              <!-- Enabled toggle -->
              <div class="col-span-2 flex justify-center">
                <button
                  @click="pref.enabled = !pref.enabled"
                  :class="[
                    'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                    pref.enabled ? 'bg-cyan-600' : 'bg-slate-700'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                      pref.enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'
                    ]"
                  ></span>
                </button>
              </div>

              <!-- Push toggle -->
              <div class="col-span-3 flex justify-center">
                <button
                  @click="pref.pushEnabled = !pref.pushEnabled; handlePushToggle(pref)"
                  :disabled="!pref.enabled || !push.isSupported.value"
                  :class="[
                    'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                    !pref.enabled || !push.isSupported.value ? 'opacity-30 cursor-not-allowed' : '',
                    pref.pushEnabled && pref.enabled ? 'bg-cyan-600' : 'bg-slate-700'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                      pref.pushEnabled && pref.enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'
                    ]"
                  ></span>
                </button>
              </div>

              <!-- Threshold input -->
              <div class="col-span-3 flex justify-center">
                <input
                  v-if="hasThreshold(pref.category)"
                  v-model.number="pref.thresholdMinutes"
                  type="number"
                  min="0"
                  max="1440"
                  :disabled="!pref.enabled"
                  class="w-16 px-2 py-1 text-xs text-center bg-slate-800 border border-slate-700 rounded-md text-slate-200 disabled:opacity-30 disabled:cursor-not-allowed focus:border-cyan-500/50 focus:outline-none"
                  :placeholder="t('notificationHub.settingsModal.threshold')"
                />
                <span v-else class="text-xs text-slate-600">-</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-800 flex justify-end gap-3 shrink-0">
          <button
            @click="emit('close')"
            class="px-4 py-2 text-sm text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-700 rounded-lg transition-colors"
          >
            {{ t('common.actions.cancel') }}
          </button>
          <button
            @click="save"
            :disabled="isSaving"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
          >
            <svg v-if="isSaving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ t('notificationHub.settingsModal.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
