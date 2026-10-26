import { ref, type Ref } from 'vue'

export function useTabQuery<Tab extends string>(validTabs: readonly Tab[], defaultTab: Tab): Ref<Tab> {
  void validTabs
  return ref(defaultTab) as Ref<Tab>
}
