import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/services/api'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(null)
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value && !!user.value)

  function setToken(newToken: string) {
    token.value = newToken
    localStorage.setItem('jwt_token', newToken)
    api.setAuthToken(newToken)
  }

  async function fetchUser() {
    loading.value = true
    try {
      user.value = await api.getMe()
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
    api.setAuthToken(null)
  }

  async function init() {
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
  }
})
