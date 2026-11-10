import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Component } from 'vue'
import OreValueTab from '@/components/ledger/OreValueTab.vue'
import type { Character } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { i18nPlugin } from './miningLedgerHarness'
import { PILOT_ONE } from './reprocessingProfileHarness'

export async function mountOreValueTab(characters: Character[] = [PILOT_ONE]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 'user-a',
    authStatus: 'valid',
    mainCharacter: PILOT_ONE,
    characters,
    corporationId: 98000001,
    corporationName: 'Deep Core Mining Inc.',
  }
  const wrapper = mount(OreValueTab as Component, { global: { plugins: [i18nPlugin(), pinia] } })
  await flushPromises()
  return wrapper
}
