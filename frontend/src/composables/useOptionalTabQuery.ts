import { ref, watch, type Ref } from 'vue'
import { onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'

export function useOptionalTabQuery<Tab extends string>(validTabs: readonly Tab[], queryKey = 'tab'): Ref<Tab | null> {
  const route = useRoute()
  const router = useRouter()

  function tabOf(queryTab: unknown): Tab | null {
    return typeof queryTab === 'string' && (validTabs as readonly string[]).includes(queryTab)
      ? queryTab as Tab
      : null
  }

  const openTab = ref(tabOf(route.query[queryKey])) as Ref<Tab | null>
  let tabAnnouncedByRoute: Tab | null | undefined

  onBeforeRouteUpdate((to) => {
    const tab = tabOf(to.query[queryKey])
    if (tab === openTab.value) return
    tabAnnouncedByRoute = tab
    openTab.value = tab
  })

  watch(openTab, (tab) => {
    const announced = tabAnnouncedByRoute
    tabAnnouncedByRoute = undefined
    if (tab === announced || tab === tabOf(route.query[queryKey])) return
    const query = { ...route.query }
    if (tab === null) {
      delete query[queryKey]
    } else {
      query[queryKey] = tab
    }
    router.replace({ query })
  })

  return openTab
}
