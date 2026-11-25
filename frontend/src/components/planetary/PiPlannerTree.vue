<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { useEveImages } from '@/composables/useEveImages'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { BuildChoice, TreeNode } from '@/utils/planetaryPlanner'

interface TreeRow {
  node: TreeNode
  depth: number
  name: string
}

const CHOICES: readonly BuildChoice[] = ['produce', 'buy']
const ICON_SIZE = 32
const INDENT_REM_PER_LEVEL = 1.25

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { typeOf, formatPlannerIsk, formatNumber } = usePlannerDisplay()

const selectedTree = computed(() => {
  const index = store.selectedIndex
  return index === null || !store.plan ? null : (store.plan.trees[index] ?? null)
})

function flatten(node: TreeNode, depth: number): TreeRow[] {
  return [{ node, depth, name: typeOf(node.typeId).name }, ...node.children.flatMap(child => flatten(child, depth + 1))]
}

const rows = computed(() => (selectedTree.value ? flatten(selectedTree.value, 0) : []))

function rowClass(node: TreeNode): string[] {
  return [
    'grid min-w-[44rem] grid-cols-[minmax(12rem,1fr)_5.5rem_4.5rem_6rem_6rem_9rem_1.5rem] items-center gap-3 border-b border-slate-800/70 px-2 py-1.5 text-sm',
    node.active ? '' : 'opacity-40',
  ]
}

function choiceButtonClass(node: TreeNode, choice: BuildChoice): string[] {
  const isPressed = node.choice === choice
  const activeColor = choice === 'produce' ? 'bg-cyan-600 text-white' : 'bg-amber-600 text-white'
  return ['px-2 py-0.5 rounded-sm text-xs font-medium transition-colors', isPressed ? activeColor : 'text-slate-400 hover:text-white hover:bg-slate-700/50']
}

function starClass(star: BuildChoice): string {
  return star === 'produce' ? 'text-cyan-400' : 'text-amber-400'
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-tree">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.tree.title') }}</h3>
      <button
        type="button"
        class="rounded-md border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200 transition-colors hover:border-cyan-500/60 hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
        data-testid="planner-apply-cheapest"
        :title="t('pi.planner.tree.applyCheapestHint')"
        :disabled="rows.length === 0"
        @click="store.applyCheapest()"
      >{{ t('pi.planner.tree.applyCheapest') }}</button>
    </div>

    <p v-if="rows.length === 0" class="text-sm text-slate-400">{{ t('pi.planner.tree.noSelection') }}</p>

    <div v-else class="overflow-x-auto">
      <div class="grid min-w-[44rem] grid-cols-[minmax(12rem,1fr)_5.5rem_4.5rem_6rem_6rem_9rem_1.5rem] items-center gap-3 border-b border-slate-700 px-2 pb-2 text-xs uppercase tracking-wider text-slate-500">
        <span>{{ t('pi.planner.tree.product') }}</span>
        <span class="text-right">{{ t('pi.planner.tree.perDay') }}</span>
        <span class="text-right">{{ t('pi.planner.tree.factories') }}</span>
        <span class="text-right">{{ t('pi.planner.tree.produceCost') }}</span>
        <span class="text-right">{{ t('pi.planner.tree.price') }}</span>
        <span class="text-center">{{ t('pi.planner.tree.choice') }}</span>
        <span></span>
      </div>

      <div
        v-for="(row, position) in rows"
        :key="position"
        :class="rowClass(row.node)"
        data-testid="planner-tree-row"
        :data-type-id="row.node.typeId"
        :data-depth="row.depth"
        :data-active="row.node.active"
        :title="row.node.active ? undefined : t('pi.planner.tree.inactive')"
      >
        <div class="flex min-w-0 items-center gap-2" :style="{ paddingLeft: `${row.depth * INDENT_REM_PER_LEVEL}rem` }">
          <img :src="getTypeIconUrl(row.node.typeId, ICON_SIZE)" alt="" class="h-5 w-5 shrink-0 rounded-sm" @error="onImageError" />
          <span :class="['shrink-0 rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[row.node.tier].badgeBg, TIER_CONFIG[row.node.tier].badgeText, TIER_CONFIG[row.node.tier].badgeBorder]">{{ row.node.tier }}</span>
          <span class="min-w-0 truncate text-slate-100" data-testid="tree-name" :title="row.name">{{ row.name }}</span>
          <span v-if="row.node.isTarget" class="shrink-0 rounded-sm bg-cyan-500/15 px-1.5 py-0.5 text-xs text-cyan-300">{{ t('pi.planner.tree.target') }}</span>
        </div>

        <span class="text-right font-mono-tech text-slate-300" data-testid="tree-per-day">{{ formatNumber(row.node.perDay, 0) }}</span>
        <span class="text-right font-mono-tech text-slate-300" data-testid="tree-factories">{{ row.node.factories === null ? '-' : formatNumber(row.node.factories, 2) }}</span>

        <template v-if="row.node.tier !== 'P0'">
          <span
            class="text-right font-mono-tech"
            :class="row.node.produceCost === null ? 'text-slate-500' : 'text-slate-200'"
            data-testid="tree-produce-cost"
            :data-state="row.node.produceCost === null ? 'unknown' : 'known'"
          >{{ row.node.produceCost === null ? t('pi.planner.unknown') : formatPlannerIsk(row.node.produceCost) }}</span>
          <span
            class="text-right font-mono-tech"
            :class="row.node.price === null ? 'text-slate-500' : 'text-slate-200'"
            data-testid="tree-price"
            :data-state="row.node.price === null ? 'unknown' : 'known'"
          >{{ row.node.price === null ? t('pi.planner.unknown') : formatPlannerIsk(row.node.price) }}</span>
        </template>
        <template v-else>
          <span></span>
          <span></span>
        </template>

        <div v-if="row.node.tier !== 'P0' && !row.node.isTarget" class="flex justify-center gap-1 rounded-md bg-slate-950 p-0.5" role="group" :aria-label="t('pi.planner.tree.choice')">
          <button
            v-for="choice in CHOICES"
            :key="choice"
            type="button"
            :data-choice="choice"
            :class="choiceButtonClass(row.node, choice)"
            :aria-pressed="row.node.choice === choice"
            @click="store.setChoice(row.node.typeId, choice)"
          >{{ t(`pi.planner.tree.${choice}`) }}</button>
        </div>
        <span v-else></span>

        <span
          v-if="row.node.star !== null"
          :class="['text-center', starClass(row.node.star)]"
          data-testid="tree-star"
          :data-star="row.node.star"
          :title="row.node.star === 'produce' ? t('pi.planner.tree.starProduce') : t('pi.planner.tree.starBuy')"
        >&#9733;</span>
        <span v-else></span>
      </div>
    </div>
  </section>
</template>
