import { ref, onMounted, onUnmounted } from 'vue'

/**
 * Generic composable for managing context menus with click-outside handling.
 */
export function useContextMenu() {
  const menuPosition = ref({ x: 0, y: 0 })
  const activeMenuId = ref<string | null>(null)

  function openMenu(id: string, event: MouseEvent, menuWidth = 224, menuHeight = 220) {
    const padding = 8

    let x = event.clientX
    let y = event.clientY + padding

    if (x + menuWidth > window.innerWidth - padding) {
      x = window.innerWidth - menuWidth - padding
    }
    if (x < padding) {
      x = padding
    }
    if (y + menuHeight > window.innerHeight - padding) {
      y = event.clientY - menuHeight - padding
    }
    if (y < padding) {
      y = padding
    }

    menuPosition.value = { x, y }
    activeMenuId.value = id
  }

  function closeMenu() {
    activeMenuId.value = null
  }

  function handleClickOutside(event: MouseEvent) {
    const target = event.target as HTMLElement
    if (!target.closest('.entry-menu') && !target.closest('.entry-row')) {
      closeMenu()
    }
  }

  onMounted(() => {
    document.addEventListener('click', handleClickOutside)
  })

  onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside)
  })

  return {
    menuPosition,
    activeMenuId,
    openMenu,
    closeMenu,
  }
}
