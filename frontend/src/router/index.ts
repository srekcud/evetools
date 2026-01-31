import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      redirect: '/dashboard',
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/Login.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/auth/eve/callback',
      name: 'eve-callback',
      component: () => import('@/views/Login.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/Dashboard.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/characters',
      name: 'characters',
      component: () => import('@/views/Characters.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/assets',
      name: 'assets',
      component: () => import('@/views/Assets.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/pve',
      name: 'pve',
      component: () => import('@/views/Pve.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/industry',
      name: 'industry',
      component: () => import('@/views/Industry.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/contracts',
      name: 'contracts',
      component: () => import('@/views/Contracts.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/shopping-list',
      name: 'shopping-list',
      component: () => import('@/views/ShoppingList.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/admin',
      name: 'admin',
      component: () => import('@/views/Admin.vue'),
      meta: { requiresAuth: true },
    },
  ],
})

router.beforeEach(async (to, _from, next) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth !== false && !authStore.isAuthenticated) {
    const token = localStorage.getItem('jwt_token')
    if (token) {
      authStore.setToken(token)
      try {
        await authStore.fetchUser()
        // Check if user was actually loaded (fetchUser doesn't throw on failure)
        if (authStore.isAuthenticated) {
          next()
        } else {
          authStore.logout()
          next({ name: 'login' })
        }
      } catch {
        authStore.logout()
        next({ name: 'login' })
      }
    } else {
      next({ name: 'login' })
    }
  } else {
    next()
  }
})

export default router
