import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to) {
    if (to.hash) {
      return { el: to.hash, behavior: 'smooth' }
    }
    return { top: 0 }
  },
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
      redirect: '/ledger',
    },
    {
      path: '/ledger',
      name: 'ledger',
      component: () => import('@/views/Ledger.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/industry',
      name: 'industry',
      component: () => import('@/views/Industry.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/escalations',
      redirect: '/ledger?tab=escalations',
    },
    {
      path: '/planetary',
      name: 'planetary',
      component: () => import('@/views/PlanetaryInteraction.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/contracts',
      redirect: '/assets?tab=contracts',
    },
    {
      path: '/shopping-list',
      name: 'shopping-list',
      component: () => import('@/views/ShoppingList.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/shopping-list/shared/:token',
      name: 'shared-shopping-list',
      component: () => import('@/views/SharedShoppingList.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/legal',
      name: 'legal',
      component: () => import('@/views/Legal.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/market',
      name: 'market',
      component: () => import('@/views/MarketBrowser.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/profit-tracker',
      redirect: '/industry?tab=profit',
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
