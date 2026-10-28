import type { VueWrapper } from '@vue/test-utils'

const SCROLL_CONTAINER_SELECTOR = '.overflow-x-auto'

export function tablesInScrollContainer(wrapper: VueWrapper): boolean[] {
  return wrapper.findAll('table').map(table => table.element.closest(SCROLL_CONTAINER_SELECTOR) !== null)
}
