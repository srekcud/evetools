import { createRouter, createWebHistory, type LocationQuery, type RouteLocationRaw } from 'vue-router'
import { rememberModulePage } from '@/composables/useModuleLastPage'
import { useAuthStore } from '@/stores/auth'

const MINING_OPERATIONS_PATH = '/mining/operations'
const MINING_MOONS_PATH = '/mining/moons'
const MINING_SITES_PATH = '/mining/sites'
const MINING_ORE_VALUE_PATH = '/mining/ore-value'
const MINING_MY_MINING_PATH = '/mining/my-mining'
interface QueriedLocation {
  query: LocationQuery
}

const UUID_PATTERN = '[0-9a-fA-F-]{36}'

function queryWithout(query: LocationQuery, key: string): LocationQuery {
  const { [key]: _removed, ...rest } = query
  return rest
}

function formerMiningOperationsLocation(to: QueriedLocation): RouteLocationRaw {
  if (to.query.tab === 'season') {
    return { path: MINING_MY_MINING_PATH, query: to.query }
  }
  if (to.query.tab === 'sites') {
    return { path: MINING_SITES_PATH, query: queryWithout(to.query, 'tab') }
  }
  if (to.query.chunk !== undefined) {
    return { path: MINING_MOONS_PATH, query: to.query }
  }
  return { path: MINING_OPERATIONS_PATH, query: to.query }
}

function formerLedgerMiningLocation(to: QueriedLocation): RouteLocationRaw | true {
  if (to.query.tab !== 'mining') {
    return true
  }
  switch (to.query.view) {
    case 'oreValue':
      return { path: MINING_ORE_VALUE_PATH }
    case 'moonChunks':
      return { path: MINING_MOONS_PATH }
    case 'moonTax':
      return { path: MINING_MOONS_PATH, query: { tab: 'tax' } }
    default:
      return { path: MINING_MY_MINING_PATH }
  }
}

function formerAssetsContractsLocation(to: QueriedLocation): RouteLocationRaw | true {
  if (to.query.tab !== 'contracts') {
    return true
  }
  return { path: '/contracts', query: queryWithout(to.query, 'tab') }
}

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
      beforeEnter: formerAssetsContractsLocation,
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
      beforeEnter: formerLedgerMiningLocation,
      meta: { requiresAuth: true },
    },
    {
      path: '/industry',
      name: 'industry',
      component: () => import('@/views/Industry.vue'),
      props: { page: 'workshop' },
      meta: { requiresAuth: true },
    },
    {
      path: '/industry/projects',
      name: 'industry-projects',
      component: () => import('@/views/GroupIndustry/GroupIndustryList.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/industry/analysis',
      name: 'industry-analysis',
      component: () => import('@/views/Industry.vue'),
      props: { page: 'analysis' },
      meta: { requiresAuth: true },
    },
    {
      path: '/industry/production',
      name: 'industry-production',
      component: () => import('@/views/Industry.vue'),
      props: { page: 'production' },
      meta: { requiresAuth: true },
    },
    {
      path: '/industry/skills',
      name: 'industry-skills',
      component: () => import('@/views/IndustrySkills.vue'),
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
      name: 'contracts',
      component: () => import('@/views/Contracts.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/shopping-list',
      redirect: to => ({ path: '/appraisal', query: to.query }),
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
      path: '/industry/join/:code',
      name: 'group-industry-join',
      component: () => import('@/views/GroupIndustry/GroupIndustryJoin.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/join/:code',
      name: 'group-industry-join-shared-link',
      redirect: to => ({ name: 'group-industry-join', params: to.params, query: to.query }),
      meta: { requiresAuth: true },
    },
    {
      path: '/mining',
      redirect: MINING_OPERATIONS_PATH,
    },
    {
      path: MINING_OPERATIONS_PATH,
      name: 'mining-operations',
      component: () => import('@/views/MiningOperation/MiningOperationList.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: `${MINING_OPERATIONS_PATH}/:id(${UUID_PATTERN})`,
      name: 'mining-operation-detail',
      component: () => import('@/views/MiningOperation/MiningOperationDetail.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: `${MINING_OPERATIONS_PATH}/join/:code`,
      name: 'mining-operation-join',
      component: () => import('@/views/MiningOperation/MiningOperationJoin.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: MINING_MOONS_PATH,
      name: 'mining-moons',
      component: () => import('@/views/Mining/MiningMoons.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: MINING_SITES_PATH,
      name: 'mining-sites',
      component: () => import('@/views/Mining/MiningSites.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: `${MINING_SITES_PATH}/:id(${UUID_PATTERN})`,
      name: 'mining-site-detail',
      component: () => import('@/views/MiningSite/MiningSiteDetail.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: MINING_ORE_VALUE_PATH,
      name: 'mining-ore-value',
      component: () => import('@/views/Mining/MiningOreValue.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: MINING_MY_MINING_PATH,
      name: 'mining-my-mining',
      component: () => import('@/views/Mining/MiningMyMining.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mining-operations',
      redirect: formerMiningOperationsLocation,
    },
    {
      path: `/mining-operations/:id(${UUID_PATTERN})`,
      redirect: to => ({ name: 'mining-operation-detail', params: to.params, query: to.query }),
    },
    {
      path: '/mining-operations/join/:code',
      redirect: to => ({ name: 'mining-operation-join', params: to.params, query: to.query }),
    },
    {
      path: `/mining-sites/:id(${UUID_PATTERN})`,
      redirect: to => ({ name: 'mining-site-detail', params: to.params, query: to.query }),
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

router.afterEach((to) => {
  rememberModulePage(to.path)
})

export default router
