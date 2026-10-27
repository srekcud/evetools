import { ref, watch, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

export function useTabQuery<Tab extends string>(validTabs: readonly Tab[], defaultTab: Tab, queryKey = 'tab'): Ref<Tab> {
  const route = useRoute()
  const router = useRouter()

  function tabFromQuery(): Tab {
    const queryTab = route.query[queryKey]
    return typeof queryTab === 'string' && (validTabs as readonly string[]).includes(queryTab)
      ? queryTab as Tab
      : defaultTab
  }

  const activeTab = ref(tabFromQuery()) as Ref<Tab>

  watch(() => route.query[queryKey], () => {
    activeTab.value = tabFromQuery()
  })

  watch(activeTab, (tab) => {
    if (tab === tabFromQuery()) return
    const query = { ...route.query }
    if (tab === defaultTab) {
      delete query[queryKey]
    } else {
      query[queryKey] = tab
    }
    router.replace({ query })
  })

  return activeTab
}
