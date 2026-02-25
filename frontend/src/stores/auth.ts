import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest, AUTH_LOGOUT_EVENT } from '@/services/api'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(null)
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value && !!user.value)

  // Listen for logout events from API (e.g., 401 errors)
  function handleLogoutEvent() {
    token.value = null
    user.value = null
  }

  // Set up event listener (called during init)
  function setupLogoutListener() {
    window.addEventListener(AUTH_LOGOUT_EVENT, handleLogoutEvent)
  }

  function teardownLogoutListener() {
    window.removeEventListener(AUTH_LOGOUT_EVENT, handleLogoutEvent)
  }

  function setToken(newToken: string) {
    token.value = newToken
    localStorage.setItem('jwt_token', newToken)
  }

  async function fetchUser() {
    loading.value = true
    try {
      user.value = await apiRequest<User>('/me')
    } catch (e) {
      user.value = null
    } finally {
      loading.value = false
    }
  }

  function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('jwt_token')
  }

  async function init() {
    // Set up listener for 401 logout events
    setupLogoutListener()

    const storedToken = localStorage.getItem('jwt_token')
    if (storedToken) {
      setToken(storedToken)
      await fetchUser()
    }
  }

  return {
    token,
    user,
    loading,
    isAuthenticated,
    setToken,
    fetchUser,
    logout,
    init,
    teardownLogoutListener,
  }
})
