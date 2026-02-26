<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const route = useRoute()
const router = useRouter()
const store = useGroupProjectStore()

type JoinState = 'loading' | 'pending' | 'error'

const state = ref<JoinState>('loading')
const errorMessage = ref('')

onMounted(async () => {
  const code = route.params.code as string
  if (!code) {
    state.value = 'error'
    errorMessage.value = 'Invalid join link'
    return
  }

  try {
    const project = await store.joinProject(code)

    if (project) {
      // Check if we were auto-accepted (we have a role set)
      if (project.myRole != null) {
        // Auto-accepted: redirect to project detail
        router.replace({ name: 'group-industry-detail', params: { id: project.id } })
        return
      }
    }

    // Request is pending approval
    state.value = 'pending'
  } catch (e) {
    const msg = e instanceof Error ? e.message : 'Failed to join project'

    // If already a member, try to find the project and redirect
    if (msg.toLowerCase().includes('already')) {
      router.replace({ name: 'group-industry' })
      return
    }

    state.value = 'error'
    errorMessage.value = msg
  }
})
</script>

<template>
  <MainLayout>
    <div class="max-w-lg mx-auto px-6 py-16">
      <!-- Loading -->
      <div v-if="state === 'loading'" class="text-center">
        <div class="bg-slate-900/80 rounded-2xl border border-slate-800 p-12">
          <LoadingSpinner size="xl" class="text-cyan-400 mx-auto mb-4" />
          <h2 class="text-lg font-semibold text-slate-100 mb-2">Joining Project...</h2>
          <p class="text-sm text-slate-500">Please wait while we process your request</p>
        </div>
      </div>

      <!-- Pending approval -->
      <div v-else-if="state === 'pending'" class="text-center">
        <div class="bg-slate-900/80 rounded-2xl border border-amber-500/20 p-12">
          <div class="w-16 h-16 rounded-full bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mx-auto mb-5">
            <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <h2 class="text-xl font-bold text-slate-100 mb-2">Request Sent</h2>
          <p class="text-sm text-slate-400 mb-6">
            Your join request has been sent and is waiting for approval from a project admin.
            You will be notified once your request is accepted.
          </p>
          <router-link
            :to="{ name: 'group-industry' }"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Back to Group Industry
          </router-link>
        </div>
      </div>

      <!-- Error -->
      <div v-else-if="state === 'error'" class="text-center">
        <div class="bg-slate-900/80 rounded-2xl border border-red-500/20 p-12">
          <div class="w-16 h-16 rounded-full bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-5">
            <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
          </div>
          <h2 class="text-xl font-bold text-slate-100 mb-2">Unable to Join</h2>
          <p class="text-sm text-slate-400 mb-6">{{ errorMessage }}</p>
          <router-link
            :to="{ name: 'group-industry' }"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Back to Group Industry
          </router-link>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
