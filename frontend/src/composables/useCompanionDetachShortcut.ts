import { useEventListener } from '@vueuse/core'
import { companionShortcutOf } from '@/views/MiningSite/miningCompanion'

export function useCompanionDetachShortcut(detach: () => void): void {
  useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (companionShortcutOf(event) === 'detach') detach()
  })
}
