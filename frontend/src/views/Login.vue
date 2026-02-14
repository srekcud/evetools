<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { safeJsonParse } from '@/services/api'
import { APP_VERSION } from '@/version'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const isLoading = ref(false)
const error = ref('')
const esiMaintenance = ref(false)
const esiStatus = ref<string | null>(null)

async function checkEsiStatus() {
  try {
    const response = await fetch('https://esi.evetech.net/status.json', {
      method: 'GET',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) {
      if (response.status === 503) {
        esiMaintenance.value = true
        esiStatus.value = 'ESI is currently in maintenance mode'
      }
      return
    }
    const data = await safeJsonParse(response)
    // ESI status.json returns an array of endpoint statuses
    // If all endpoints have issues, we're in maintenance
    if (Array.isArray(data) && data.every((s: { status: string }) => s.status !== 'green')) {
      esiMaintenance.value = true
      esiStatus.value = 'ESI services are degraded'
    }
  } catch {
    // Network error - ESI might be completely down
    esiMaintenance.value = true
    esiStatus.value = 'Cannot reach ESI servers'
  }
}

onMounted(async () => {
  // Check ESI status
  checkEsiStatus()

  // Check if we have an OAuth code from EVE callback
  const code = route.query.code as string
  const state = route.query.state as string
  if (code) {
    isLoading.value = true
    try {
      // Check if this is an add-character or reauthorize flow
      const oauthAction = sessionStorage.getItem('eve_oauth_action')
      const existingToken = authStore.token || localStorage.getItem('jwt_token')

      // Build request headers
      const headers: Record<string, string> = {}
      if (oauthAction && existingToken) {
        headers['Authorization'] = `Bearer ${existingToken}`
      }

      // Build exchange URL with code and state
      const params = new URLSearchParams({ code })
      if (state) {
        params.set('state', state)
      }

      const response = await fetch(`/auth/eve/exchange?${params.toString()}`, {
        headers,
      })
      const data = await safeJsonParse<{ error?: boolean; message?: string; token?: string }>(response)

      // Clean up sessionStorage
      sessionStorage.removeItem('eve_oauth_action')
      sessionStorage.removeItem('eve_oauth_state')
      sessionStorage.removeItem('eve_oauth_character_id')

      if (oauthAction === 'add-character' || oauthAction === 'reauthorize') {
        // Character was added/reauthorized, redirect to characters page
        if (data.error) {
          error.value = data.message || 'Failed to add character'
        } else {
          // Refresh user data to get updated characters list
          await authStore.fetchUser()
          router.push('/characters')
        }
        return
      }

      if (data.token) {
        authStore.setToken(data.token)
        await authStore.fetchUser()
        router.push('/dashboard')
        return
      } else {
        error.value = data.message || 'Authentication failed'
      }
    } catch (e) {
      // Check if this might be an ESI maintenance issue
      await checkEsiStatus()
      if (esiMaintenance.value) {
        error.value = 'Authentication failed - EVE servers may be under maintenance'
      } else {
        error.value = 'Authentication error'
      }
      console.error(e)
    } finally {
      isLoading.value = false
    }
    return
  }

  // Check if we have a JWT token from the OAuth callback (legacy)
  const token = route.query.token as string
  if (token) {
    authStore.setToken(token)
    await authStore.fetchUser()
    router.push('/dashboard')
    return
  }

  // If already authenticated, redirect to dashboard
  if (authStore.isAuthenticated) {
    router.push('/dashboard')
  }
})

async function loginWithEve() {
  isLoading.value = true
  error.value = ''

  try {
    const response = await fetch('/auth/eve/redirect')
    const data = await safeJsonParse<{ redirect_url?: string; state?: string }>(response)

    if (data.redirect_url) {
      // Store state in sessionStorage for CSRF verification
      sessionStorage.setItem('eve_oauth_state', data.state || '')
      window.location.href = data.redirect_url
    } else {
      error.value = 'Failed to get redirect URL'
    }
  } catch (e) {
    await checkEsiStatus()
    if (esiMaintenance.value) {
      error.value = 'Cannot connect - EVE servers may be under maintenance'
    } else {
      error.value = 'Connection error'
    }
    console.error(e)
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-eve-dark flex items-center justify-center relative overflow-hidden">
    <!-- Animated background -->
    <div class="absolute inset-0">
      <div class="absolute inset-0 bg-gradient-to-br from-eve-dark via-[#0d1015] to-eve-dark"></div>
      <div class="absolute inset-0 opacity-10">
        <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-eve-accent rounded-full filter blur-[100px] animate-pulse-slow"></div>
        <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-eve-accent-dim rounded-full filter blur-[100px] animate-pulse-slow" style="animation-delay: 1s"></div>
      </div>
      <!-- Grid overlay -->
      <div class="absolute inset-0 opacity-5" style="background-image: linear-gradient(rgba(0,212,255,0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(0,212,255,0.1) 1px, transparent 1px); background-size: 50px 50px;"></div>
    </div>

    <!-- Login card -->
    <div class="relative z-10 eve-card p-12 max-w-md w-full mx-4">
      <!-- Logo -->
      <div class="text-center mb-10">
        <div class="inline-flex items-center justify-center w-20 h-20 mb-6">
          <svg viewBox="0 0 100 100" class="w-full h-full">
            <defs>
              <linearGradient id="logoGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#00d4ff" />
                <stop offset="100%" style="stop-color:#0099bb" />
              </linearGradient>
            </defs>
            <polygon fill="url(#logoGrad)" points="50,5 95,27.5 95,72.5 50,95 5,72.5 5,27.5" />
            <text x="50" y="62" text-anchor="middle" fill="#0a0c0f" font-family="Rajdhani, sans-serif" font-size="36" font-weight="bold">E</text>
          </svg>
        </div>
        <h1 class="text-4xl font-bold text-eve-text-bright mb-2 tracking-wide">EVE Tools</h1>
        <p class="text-eve-text">Industrial Management Suite</p>
      </div>

      <!-- ESI Maintenance warning -->
      <div v-if="esiMaintenance" class="mb-6 p-4 bg-amber-500/20 border border-amber-500/50 rounded-lg">
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div>
            <p class="text-amber-200 text-sm font-medium">EVE Online servers are under maintenance</p>
            <p class="text-amber-200/70 text-xs mt-1">{{ esiStatus }}</p>
          </div>
        </div>
      </div>

      <!-- Error message -->
      <div v-if="error" class="mb-6 p-3 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400 text-sm text-center">
        {{ error }}
      </div>

      <!-- Loading state -->
      <div v-if="isLoading" class="flex flex-col items-center gap-4">
        <svg class="w-10 h-10 animate-spin text-eve-accent" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-eve-text">Connecting to EVE Online...</p>
      </div>

      <!-- EVE SSO Login button (official) -->
      <button
        v-else
        @click="loginWithEve"
        class="w-full flex justify-center transition-all duration-300 hover:scale-105 hover:drop-shadow-[0_0_15px_rgba(0,212,255,0.4)] focus:outline-none focus:ring-2 focus:ring-eve-accent/50 rounded"
      >
        <img
          src="https://web.ccpgamescdn.com/eveonlineassets/developers/eve-sso-login-black-large.png"
          alt="Log in with EVE Online"
          class="h-auto max-w-full"
        />
      </button>

      <!-- Info text -->
      <p class="text-center text-eve-text/60 text-sm mt-8">
        Secure authentication via EVE Online SSO
      </p>
      <p class="text-center text-eve-text/40 text-xs mt-2">
        Your credentials are never shared with this application
      </p>
    </div>

    <!-- Version -->
    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 text-eve-text/40 font-mono text-xs">
      v{{ APP_VERSION }}
    </div>
  </div>
</template>
