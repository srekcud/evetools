import { ref } from 'vue'
import { authFetch, safeJsonParse } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { AmmoType } from '@/types/pve'

export function usePveTypeSettings(
  onDeclinedCounts: (contracts: number, transactions: number) => void,
) {
  const authStore = useAuthStore()

  // Ammo settings
  const ammoTypes = ref<AmmoType[]>([])
  const ammoSearchResults = ref<AmmoType[]>([])
  const isSearchingAmmo = ref(false)
  const isAddingAmmo = ref(false)

  // Loot types settings
  const lootTypes = ref<AmmoType[]>([])
  const lootSearchResults = ref<AmmoType[]>([])
  const isSearchingLoot = ref(false)
  const isAddingLootType = ref(false)

  async function fetchSettings() {
    try {
      const response = await authFetch('/api/pve/settings', {
        headers: { 'Authorization': `Bearer ${authStore.token}` }
      })
      if (response.ok) {
        const data = await safeJsonParse<{
          ammoTypes: AmmoType[];
          lootTypes?: AmmoType[];
          declinedContractsCount?: number;
          declinedTransactionsCount?: number;
        }>(response)
        ammoTypes.value = data.ammoTypes
        lootTypes.value = data.lootTypes || []
        onDeclinedCounts(
          data.declinedContractsCount || 0,
          data.declinedTransactionsCount || 0,
        )
      }
    } catch (e) {
      console.error('Failed to fetch ammo settings:', e)
    }
  }

  async function searchAmmoTypes(query: string) {
    if (query.length < 2) return

    isSearchingAmmo.value = true
    try {
      const response = await authFetch(`/api/pve/search-types?query=${encodeURIComponent(query)}`, {
        headers: { 'Authorization': `Bearer ${authStore.token}` }
      })
      if (response.ok) {
        const types = await safeJsonParse<AmmoType[]>(response)
        const existingIds = ammoTypes.value.map(a => a.typeId)
        ammoSearchResults.value = types.filter((t: AmmoType) => !existingIds.includes(t.typeId))
      }
    } catch (e) {
      console.error('Failed to search ammo types:', e)
    } finally {
      isSearchingAmmo.value = false
    }
  }

  async function addAmmoType(typeId: number) {
    isAddingAmmo.value = true
    try {
      const response = await authFetch('/api/pve/settings/ammo', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ typeId }),
      })
      if (response.ok) {
        await fetchSettings()
        ammoSearchResults.value = []
      }
    } catch (e) {
      console.error('Failed to add ammo type:', e)
    } finally {
      isAddingAmmo.value = false
    }
  }

  async function removeAmmoType(typeId: number) {
    try {
      const response = await authFetch(`/api/pve/settings/ammo/${typeId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({})
      })
      if (response.ok) {
        await fetchSettings()
      }
    } catch (e) {
      console.error('Failed to remove ammo type:', e)
    }
  }

  async function searchLootTypes(query: string) {
    if (query.length < 2) return

    isSearchingLoot.value = true
    try {
      const response = await authFetch(`/api/pve/search-types?query=${encodeURIComponent(query)}`, {
        headers: { 'Authorization': `Bearer ${authStore.token}` }
      })
      if (response.ok) {
        const types = await safeJsonParse<AmmoType[]>(response)
        const existingIds = lootTypes.value.map(l => l.typeId)
        lootSearchResults.value = types.filter((t: AmmoType) => !existingIds.includes(t.typeId))
      }
    } catch (e) {
      console.error('Failed to search loot types:', e)
    } finally {
      isSearchingLoot.value = false
    }
  }

  async function addLootType(typeId: number) {
    isAddingLootType.value = true
    try {
      const response = await authFetch('/api/pve/settings/loot', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ typeId }),
      })
      if (response.ok) {
        await fetchSettings()
        lootSearchResults.value = []
      }
    } catch (e) {
      console.error('Failed to add loot type:', e)
    } finally {
      isAddingLootType.value = false
    }
  }

  async function removeLootType(typeId: number) {
    try {
      const response = await authFetch(`/api/pve/settings/loot/${typeId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({})
      })
      if (response.ok) {
        await fetchSettings()
      }
    } catch (e) {
      console.error('Failed to remove loot type:', e)
    }
  }

  return {
    // Ammo
    ammoTypes,
    ammoSearchResults,
    isSearchingAmmo,
    isAddingAmmo,
    searchAmmoTypes,
    addAmmoType,
    removeAmmoType,

    // Loot
    lootTypes,
    lootSearchResults,
    isSearchingLoot,
    isAddingLootType,
    searchLootTypes,
    addLootType,
    removeLootType,

    // Shared
    fetchSettings,
  }
}
