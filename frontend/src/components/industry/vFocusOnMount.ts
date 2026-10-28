import type { Directive } from 'vue'

export const vFocusOnMount: Directive<HTMLElement> = {
  mounted: (element) => element.focus(),
}
