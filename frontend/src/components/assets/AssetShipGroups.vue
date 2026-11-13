<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useTypeNames } from '@/composables/useTypeNames'
import { formatEft, groupAssembledShips } from '@/utils/shipFit'
import type { ShipGroup } from '@/utils/shipFit'
import AssetVolumeBadge from '@/components/assets/AssetVolumeBadge.vue'
import { summarizeVolume, type PackagedVolumeCatalog } from '@/utils/assetVolume'
import type { Asset } from '@/types'

const COLLAPSED_GROUP_LIMIT = 8
const COPY_FEEDBACK_DURATION_MS = 2000

const props = defineProps<{
  ships: Asset[]
  contentsOf: (itemId: number) => Asset[]
  packagedVolumes?: PackagedVolumeCatalog | null
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const { typeNameLabel } = useTypeNames()

const showAll = ref(false)
const openGroupKeys = ref<Set<string>>(new Set())
const copyStateByKey = ref<Record<string, 'copied' | 'failed'>>({})
const resetTimers: ReturnType<typeof setTimeout>[] = []

const groups = computed(() => groupAssembledShips(props.ships, props.contentsOf))
const visibleGroups = computed(() => (showAll.value ? groups.value : groups.value.slice(0, COLLAPSED_GROUP_LIMIT)))
const hiddenGroupCount = computed(() => Math.max(0, groups.value.length - COLLAPSED_GROUP_LIMIT))

const slotSections = [
  { kind: 'low', label: 'assets.shipGroups.low' },
  { kind: 'mid', label: 'assets.shipGroups.mid' },
  { kind: 'high', label: 'assets.shipGroups.high' },
  { kind: 'rig', label: 'assets.shipGroups.rig' },
  { kind: 'subsystem', label: 'assets.shipGroups.subsystem' },
] as const

function groupTitle(group: ShipGroup): string {
  const hull = typeNameLabel(group.representative.typeName, group.representative.typeId)
  const name = group.representative.itemName
  const count = group.ships.length
  return name !== null && name !== '' ? `${hull} · ${name} ×${count}` : `${hull} ×${count}`
}

function groupVolume(group: ShipGroup) {
  return props.packagedVolumes ? summarizeVolume(group.ships, props.contentsOf, props.packagedVolumes) : null
}

function toggleGroup(key: string): void {
  const next = new Set(openGroupKeys.value)
  if (next.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }
  openGroupKeys.value = next
}

function copyLabel(group: ShipGroup): string {
  if (formatEft(group) === null) {
    return t('assets.shipGroups.copyUnavailable')
  }
  const state = copyStateByKey.value[group.key]
  if (state === 'copied') return t('assets.shipGroups.copied')
  if (state === 'failed') return t('assets.shipGroups.copyFailed')
  return t('assets.shipGroups.copyEft')
}

async function copyEft(group: ShipGroup): Promise<void> {
  const eft = formatEft(group)
  if (eft === null) {
    return
  }
  try {
    await navigator.clipboard.writeText(eft)
    copyStateByKey.value = { ...copyStateByKey.value, [group.key]: 'copied' }
  } catch {
    copyStateByKey.value = { ...copyStateByKey.value, [group.key]: 'failed' }
  }
  resetTimers.push(setTimeout(() => {
    const { [group.key]: _cleared, ...rest } = copyStateByKey.value
    copyStateByKey.value = rest
  }, COPY_FEEDBACK_DURATION_MS))
}

onBeforeUnmount(() => {
  resetTimers.forEach(clearTimeout)
})
</script>

<template>
  <div v-if="groups.length > 0" data-testid="ship-groups" class="divide-y divide-slate-800">
    <div v-for="group in visibleGroups" :key="group.key" data-testid="ship-group">
      <div class="px-5 py-3 flex items-center justify-between gap-3 hover:bg-slate-800/30 transition-colors">
        <div class="flex items-center gap-3 min-w-0">
          <img
            :src="getTypeIconUrl(group.representative.typeId, 32, group.representative.categoryId ?? undefined)"
            :alt="typeNameLabel(group.representative.typeName, group.representative.typeId)"
            class="w-8 h-8 rounded-sm"
            loading="lazy"
            @error="onImageError"
          />
          <button
            type="button"
            data-testid="ship-group-toggle"
            :aria-expanded="openGroupKeys.has(group.key)"
            :title="openGroupKeys.has(group.key) ? t('assets.shipGroups.hideFit') : t('assets.shipGroups.showFit')"
            class="flex items-center gap-2 min-w-0 text-left text-slate-200 hover:text-cyan-300"
            @click="toggleGroup(group.key)"
          >
            <span data-testid="ship-group-title" class="truncate">{{ groupTitle(group) }}</span>
            <svg
              :class="['w-3 h-3 shrink-0 text-slate-500 transition-transform', openGroupKeys.has(group.key) && 'rotate-180']"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          <span
            v-if="group.badFit"
            data-testid="ship-group-bad-fit"
            class="px-2 py-0.5 rounded-sm text-xs font-medium uppercase tracking-wide bg-amber-500/10 text-amber-400 border border-amber-500/30"
          >{{ t('assets.shipGroups.badFit') }}</span>
        </div>
        <AssetVolumeBadge
          v-if="groupVolume(group)"
          :volume="groupVolume(group)!"
          test-id="ship-group-volume"
          class="ml-auto shrink-0"
        />
        <button
          type="button"
          data-testid="ship-group-copy-eft"
          :data-state="copyStateByKey[group.key] ?? 'idle'"
          :disabled="formatEft(group) === null"
          :title="copyLabel(group)"
          :aria-label="copyLabel(group)"
          :class="[
            'shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
            copyStateByKey[group.key] === 'copied' ? 'text-emerald-400' : copyStateByKey[group.key] === 'failed' ? 'text-red-400' : 'text-slate-500 hover:text-cyan-300',
          ]"
          @click="copyEft(group)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
          </svg>
        </button>
      </div>

      <div
        v-if="openGroupKeys.has(group.key)"
        data-testid="ship-group-fit"
        class="bg-slate-950/50 border-l-2 border-cyan-500/30 px-5 py-3 pl-12 space-y-2"
      >
        <template v-for="section in slotSections" :key="section.kind">
          <div v-if="group.fit[section.kind].length > 0">
            <div class="text-xs uppercase tracking-wide text-slate-500">{{ t(section.label) }}</div>
            <div v-for="module in group.fit[section.kind]" :key="module.id" class="text-sm text-slate-300">
              {{ typeNameLabel(module.typeName, module.typeId) }}
            </div>
          </div>
        </template>
        <div v-if="group.fit.drones.length > 0">
          <div class="text-xs uppercase tracking-wide text-slate-500">{{ t('assets.shipGroups.drones') }}</div>
          <div v-for="stack in group.fit.drones" :key="stack.typeId" class="text-sm text-slate-300">
            {{ typeNameLabel(stack.typeName, stack.typeId) }} <span class="font-mono text-slate-500">x{{ stack.quantity.toLocaleString() }}</span>
          </div>
        </div>
        <div v-if="group.fit.cargo.length > 0">
          <div class="text-xs uppercase tracking-wide text-slate-500">{{ t('assets.shipGroups.cargo') }}</div>
          <div v-for="stack in group.fit.cargo" :key="stack.typeId" class="text-sm text-slate-300">
            {{ typeNameLabel(stack.typeName, stack.typeId) }} <span class="font-mono text-slate-500">x{{ stack.quantity.toLocaleString() }}</span>
          </div>
        </div>
      </div>
    </div>

    <button
      v-if="hiddenGroupCount > 0 && !showAll"
      type="button"
      data-testid="ship-groups-show-all"
      class="w-full px-5 py-2 text-sm text-cyan-400 hover:bg-slate-800/30 transition-colors text-left"
      @click="showAll = true"
    >{{ t('assets.shipGroups.showAll', { count: groups.length }) }}</button>
  </div>
</template>
