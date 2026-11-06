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
      path: '/appraisal',
      name: 'appraisal',
      component: () => import('@/views/Appraisal.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/appraisal/shared/:token',
      name: 'shared-appraisal',
      component: () => import('@/views/SharedShoppingList.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/shopping-list/shared/:token',
      redirect: to => ({ name: 'shared-appraisal', params: { token: to.params.token } }),
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
      path: '/group-industry',
      name: 'group-industry',
      component: () => import('@/views/GroupIndustry/GroupIndustryList.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/industry/projects/:id',
      name: 'industry-project',
      component: () => import('@/views/GroupIndustry/GroupIndustryDetail.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/group-industry/:id',
      name: 'group-industry-detail',
      redirect: to => ({ name: 'industry-project', params: to.params, query: to.query }),
      meta: { requiresAuth: true },
    },
    {
      path: '/join/:code',
      name: 'group-industry-join',
      component: () => import('@/views/GroupIndustry/GroupIndustryJoin.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mining-operations',
      name: 'mining-operations',
      component: () => import('@/views/MiningOperation/MiningOperationList.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mining-operations/:id([0-9a-fA-F-]{36})',
      name: 'mining-operation-detail',
      component: () => import('@/views/MiningOperation/MiningOperationDetail.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mining-operations/join/:code',
      name: 'mining-operation-join',
      component: () => import('@/views/MiningOperation/MiningOperationJoin.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mining-sites/:id([0-9a-fA-F-]{36})',
      name: 'mining-site-detail',
      component: () => import('@/views/MiningSite/MiningSiteDetail.vue'),
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
