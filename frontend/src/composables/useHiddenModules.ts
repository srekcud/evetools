import { ref } from 'vue'
import {
  isModuleHidden,
  moduleIdsToUnhide,
  readHiddenModules,
  saveHiddenModules,
} from '@/constants/sidebarNavigation'

const hiddenModules = ref<string[]>([])

export function useHiddenModules() {
  function reloadHiddenModules(): void {
    hiddenModules.value = readHiddenModules()
  }

  function isModuleVisible(moduleId: string): boolean {
    return !isModuleHidden(hiddenModules.value, moduleId)
  }

  function toggleModule(moduleId: string): void {
    if (isModuleHidden(hiddenModules.value, moduleId)) {
      const idsToUnhide = moduleIdsToUnhide(moduleId)
      hiddenModules.value = hiddenModules.value.filter(hiddenId => !idsToUnhide.includes(hiddenId))
    } else {
      hiddenModules.value = [...hiddenModules.value, moduleId]
    }
    saveHiddenModules(hiddenModules.value)
  }

  return { hiddenModules, reloadHiddenModules, isModuleVisible, toggleModule }
}
