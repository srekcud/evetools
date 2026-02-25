<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import MainCharacterCard from '@/components/characters/MainCharacterCard.vue'
import CharacterCard from '@/components/characters/CharacterCard.vue'

const { t } = useI18n()

interface Character {
  id: string
  eveCharacterId: number
  name: string
  corporationId: number
  corporationName: string
  allianceId: number | null
  allianceName: string | null
  isMain: boolean
  hasValidToken: boolean
  hasMissingScopes: boolean
  lastSyncAt: string | null
}

interface SkillQueue {
  characterId: string
  skillId: number
  skillName: string
  finishedLevel: number | null
  finishDate: string
  queueSize: number
}

const authStore = useAuthStore()

const characters = ref<Character[]>([])
const wallets = ref<Map<string, number>>(new Map())
const skillQueues = ref<Map<string, SkillQueue>>(new Map())
const isPageLoading = ref(true)
const isLoading = ref(false)
const error = ref('')
const showDeleteModal = ref(false)
const showSetMainModal = ref(false)
const selectedCharacter = ref<Character | null>(null)

const mainCharacter = computed(() => characters.value.find(c => c.isMain))
const altCharacters = computed(() => characters.value.filter(c => !c.isMain))
const altsBySameCorp = computed(() =>
  altCharacters.value.filter(c => c.corporationId === mainCharacter.value?.corporationId)
)
const altsByDifferentCorp = computed(() =>
  altCharacters.value.filter(c => c.corporationId !== mainCharacter.value?.corporationId)
)

onMounted(async () => {
  await Promise.all([fetchCharacters(), fetchWallets(), fetchSkillQueues()])
})

async function fetchCharacters() {
  isPageLoading.value = true
  error.value = ''
  try {
    const response = await authFetch('/api/me/characters', {
      headers: { 'Authorization': `Bearer ${authStore.token}` },
    })
    if (!response.ok) throw new Error('Failed to fetch characters')
    const data = await safeJsonParse<Character[] | { member?: Character[] }>(response)
    characters.value = Array.isArray(data) ? data : (data.member || [])
  } catch (e) {
    error.value = 'Failed to load characters'
    console.error(e)
  } finally {
    isPageLoading.value = false
  }
}

async function fetchWallets() {
  try {
    const response = await authFetch('/api/me/wallets', {
      headers: { 'Authorization': `Bearer ${authStore.token}` },
    })
    if (!response.ok) return
    const data = await safeJsonParse<{ wallets?: { characterId: string; balance: number }[] }>(response)
    const map = new Map<string, number>()
    for (const w of data.wallets || []) {
      map.set(w.characterId, w.balance)
    }
    wallets.value = map
  } catch (e) {
    console.error('Failed to fetch wallets', e)
  }
}

async function fetchSkillQueues() {
  try {
    const response = await authFetch('/api/me/skillqueues', {
      headers: { 'Authorization': `Bearer ${authStore.token}` },
    })
    if (!response.ok) return
    const data = await safeJsonParse<{ skillQueues?: SkillQueue[] }>(response)
    const map = new Map<string, SkillQueue>()
    for (const sq of data.skillQueues || []) {
      map.set(sq.characterId, sq)
    }
    skillQueues.value = map
  } catch (e) {
    console.error('Failed to fetch skill queues', e)
  }
}

async function addCharacter() {
  isLoading.value = true
  error.value = ''
  try {
    const response = await fetch('/auth/eve/redirect', {
      headers: { 'Authorization': `Bearer ${authStore.token}` },
    })
    const data = await safeJsonParse<{ redirect_url?: string; state?: string }>(response)
    if (data.redirect_url) {
      sessionStorage.setItem('eve_oauth_state', data.state || '')
      sessionStorage.setItem('eve_oauth_action', 'add-character')
      window.location.href = data.redirect_url
    } else {
      error.value = 'Failed to get redirect URL'
    }
  } catch (e) {
    error.value = 'Connection error'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

function confirmDelete(character: Character) {
  selectedCharacter.value = character
  showDeleteModal.value = true
}

async function deleteCharacter() {
  if (!selectedCharacter.value) return
  isLoading.value = true
  error.value = ''
  try {
    const response = await authFetch(`/api/me/characters/${selectedCharacter.value.id}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${authStore.token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    })
    if (!response.ok) {
      const data = await safeJsonParse<{ message?: string }>(response)
      throw new Error(data.message || 'Failed to delete character')
    }
    characters.value = characters.value.filter(c => c.id !== selectedCharacter.value?.id)
    showDeleteModal.value = false
    selectedCharacter.value = null
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to delete character'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

function confirmSetMain(character: Character) {
  selectedCharacter.value = character
  showSetMainModal.value = true
}

async function setAsMain() {
  if (!selectedCharacter.value) return
  isLoading.value = true
  error.value = ''
  try {
    const response = await authFetch(`/api/me/characters/${selectedCharacter.value.id}/set-main`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${authStore.token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    })
    if (!response.ok) {
      const data = await safeJsonParse<{ message?: string }>(response)
      throw new Error(data.message || 'Failed to set main character')
    }
    characters.value = characters.value.map(c => ({
      ...c,
      isMain: c.id === selectedCharacter.value?.id
    }))
    await authStore.fetchUser()
    showSetMainModal.value = false
    selectedCharacter.value = null
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to set main character'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

async function reauthorize(character: Character) {
  isLoading.value = true
  error.value = ''
  try {
    const response = await fetch('/auth/eve/redirect', {
      headers: { 'Authorization': `Bearer ${authStore.token}` },
    })
    const data = await safeJsonParse<{ redirect_url?: string; state?: string }>(response)
    if (data.redirect_url) {
      sessionStorage.setItem('eve_oauth_state', data.state || '')
      sessionStorage.setItem('eve_oauth_action', 'reauthorize')
      sessionStorage.setItem('eve_oauth_character_id', character.id)
      window.location.href = data.redirect_url
    } else {
      error.value = 'Failed to get redirect URL'
    }
  } catch (e) {
    error.value = 'Connection error'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <MainLayout>
      <!-- Header -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-2xl font-bold text-slate-100 tracking-wide">{{ t('characters.title') }}</h1>
          <p class="text-slate-400 mt-1">{{ t('characters.subtitle') }}</p>
        </div>
        <button
          @click="addCharacter"
          :disabled="isLoading"
          class="px-4 py-2 bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('characters.addCharacter') }}
        </button>
      </div>

      <!-- Error message -->
      <ErrorBanner v-if="error" :message="error" class="mb-6" @dismiss="error = ''" />

      <!-- Loading state -->
      <div v-if="isPageLoading" class="flex flex-col items-center justify-center py-24">
        <div class="relative">
          <div class="w-16 h-16 border-4 border-cyan-500/20 border-t-cyan-500 rounded-full animate-spin"></div>
          <div class="absolute inset-0 w-16 h-16 border-4 border-transparent border-b-cyan-400/50 rounded-full animate-spin" style="animation-direction: reverse; animation-duration: 1.5s;"></div>
        </div>
        <p class="text-slate-400 mt-6 animate-pulse">{{ t('characters.loading') }}</p>
      </div>

      <template v-else>
        <!-- Main Character Card -->
        <section class="mb-10">
          <h2 class="text-xs font-semibold text-cyan-400 uppercase tracking-widest mb-4 flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
              <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
            </svg>
            {{ t('characters.mainCharacter') }}
          </h2>

          <MainCharacterCard
            v-if="mainCharacter"
            :character="mainCharacter"
            :wallet="wallets.get(mainCharacter.id)"
            :skill-queue="skillQueues.get(mainCharacter.id)"
            :is-loading="isLoading"
            @reauthorize="reauthorize"
          />

          <!-- No main -->
          <div v-else class="bg-slate-900/50 rounded-2xl border border-dashed border-slate-700 p-12 text-center">
            <svg class="w-16 h-16 mx-auto text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <p class="text-slate-500">{{ t('characters.noMainCharacter') }}</p>
          </div>
        </section>

        <!-- Alt Characters - Same Corporation -->
        <section v-if="altsBySameCorp.length > 0" class="mb-10">
          <h2 class="text-xs font-semibold text-cyan-400 uppercase tracking-widest mb-4 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            {{ t('characters.sameCorp') }} ({{ altsBySameCorp.length }})
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <CharacterCard
              v-for="character in altsBySameCorp"
              :key="character.id"
              :character="character"
              :wallet="wallets.get(character.id)"
              :skill-queue="skillQueues.get(character.id)"
              :is-loading="isLoading"
              variant="corp"
              @reauthorize="reauthorize"
              @set-main="confirmSetMain"
              @delete="confirmDelete"
            />
          </div>
        </section>

        <!-- Alt Characters - Different Corporation -->
        <section v-if="altsByDifferentCorp.length > 0" class="mb-10">
          <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
            </svg>
            {{ t('characters.otherCorps') }} ({{ altsByDifferentCorp.length }})
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <CharacterCard
              v-for="character in altsByDifferentCorp"
              :key="character.id"
              :character="character"
              :wallet="wallets.get(character.id)"
              :skill-queue="skillQueues.get(character.id)"
              :is-loading="isLoading"
              variant="other"
              @reauthorize="reauthorize"
              @set-main="confirmSetMain"
              @delete="confirmDelete"
            />
          </div>
        </section>

        <!-- Empty state -->
        <div v-if="altCharacters.length === 0" class="text-center py-16 bg-slate-900/30 rounded-2xl border border-dashed border-slate-700">
          <svg class="w-16 h-16 mx-auto text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
          </svg>
          <p class="text-slate-500 mb-4">{{ t('characters.noAlts') }}</p>
          <button
            @click="addCharacter"
            :disabled="isLoading"
            class="px-4 py-2 bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed mx-auto"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            {{ t('characters.addCharacter') }}
          </button>
        </div>
      </template>

    <!-- Delete Modal -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-all duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-all duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="showDeleteModal = false" @keydown.enter="deleteCharacter" @keydown.escape="showDeleteModal = false">
          <Transition
            enter-active-class="transition-all duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition-all duration-150"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
          >
            <div v-if="showDeleteModal" class="bg-slate-900 rounded-2xl border border-slate-800 max-w-sm w-full p-6 shadow-2xl">
              <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-xl bg-red-500/10 flex items-center justify-center">
                  <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </div>
                <div>
                  <h3 class="text-lg font-semibold text-slate-100">Remove Character</h3>
                  <p class="text-sm text-slate-400">This action cannot be undone</p>
                </div>
              </div>
              <p class="text-slate-400 text-sm mb-6">
                Remove <strong class="text-slate-200">{{ selectedCharacter?.name }}</strong>? This will delete all cached data for this character.
              </p>
              <div class="flex gap-3">
                <button
                  @click="showDeleteModal = false"
                  :disabled="isLoading"
                  class="flex-1 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-xl text-slate-300 font-medium transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  @click="deleteCharacter"
                  :disabled="isLoading"
                  class="flex-1 py-2.5 bg-red-600 hover:bg-red-500 rounded-xl font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
                >
                  <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  {{ isLoading ? 'Removing...' : 'Remove' }}
                </button>
              </div>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>

    <!-- Set Main Modal -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-all duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-all duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showSetMainModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="showSetMainModal = false" @keydown.enter="setAsMain" @keydown.escape="showSetMainModal = false">
          <Transition
            enter-active-class="transition-all duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition-all duration-150"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
          >
            <div v-if="showSetMainModal" class="bg-slate-900 rounded-2xl border border-slate-800 max-w-sm w-full p-6 shadow-2xl">
              <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center">
                  <svg class="w-6 h-6 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                  </svg>
                </div>
                <div>
                  <h3 class="text-lg font-semibold text-slate-100">Set as Main</h3>
                  <p class="text-sm text-slate-400">Change your main character</p>
                </div>
              </div>
              <p class="text-slate-400 text-sm mb-4">
                Make <strong class="text-slate-200">{{ selectedCharacter?.name }}</strong> your main character?
              </p>
              <div v-if="selectedCharacter?.corporationId !== mainCharacter?.corporationId" class="text-amber-400 text-sm mb-6 p-3 bg-amber-500/10 rounded-xl border border-amber-500/20 flex items-start gap-2">
                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span>This character is in a different corporation. Your corp reference will change.</span>
              </div>
              <div class="flex gap-3">
                <button
                  @click="showSetMainModal = false"
                  :disabled="isLoading"
                  class="flex-1 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-xl text-slate-300 font-medium transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  @click="setAsMain"
                  :disabled="isLoading"
                  class="flex-1 py-2.5 bg-amber-600 hover:bg-amber-500 rounded-xl font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
                >
                  <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  {{ isLoading ? 'Updating...' : 'Confirm' }}
                </button>
              </div>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>
  </MainLayout>
</template>
