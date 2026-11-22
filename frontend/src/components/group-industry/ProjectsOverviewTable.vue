<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { groupProjectLabel } from '@/utils/groupProjectLabel'
import type { GroupMemberRole, GroupProject, ProjectOverview, ProjectOverviewFlow } from '@/stores/group-industry/types'

type FlowColumn = keyof ProjectOverviewFlow

interface FlowCell {
  text: string
  title: string | null
  active: boolean
}

const FLOW_COLUMNS: FlowColumn[] = ['toBring', 'onSite', 'inProduction', 'out', 'done']
const FLOW_REASONS_WITHOUT_FLOW = ['not_started', 'completed']
const AWAITED_SEPARATOR = ' · '

const props = defineProps<{
  projects: GroupProject[]
  overviews: ProjectOverview[]
  overviewError: string | null
}>()

const emit = defineEmits<{
  open: [project: GroupProject]
}>()

const { t, te } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()

const overviewByProjectId = computed(() => new Map(props.overviews.map(overview => [overview.projectId, overview])))

function overviewOf(project: GroupProject): ProjectOverview | undefined {
  return overviewByProjectId.value.get(project.id)
}

function roleOf(project: GroupProject): GroupMemberRole | null {
  const overview = overviewOf(project)
  return overview === undefined ? project.myRole : overview.myRole
}

function roleLabel(project: GroupProject): string {
  const role = roleOf(project)
  return role === null ? '' : t(`groupIndustry.members.roles.${role}`)
}

function teamLabel(project: GroupProject): string {
  const overview = overviewOf(project)
  if (overview === undefined) return project.ownerCharacterName
  if (overview.team.length === 0) return overview.ownerName
  return `${overview.ownerName} · ${overview.team.map(member => member.name).join(', ')}`
}

function summaryOf(project: GroupProject): string {
  const totalRuns = project.items.reduce((sum, item) => sum + item.runs, 0)
  if (project.items.length === 1) return t('groupIndustry.overview.summary.runs', { count: totalRuns })
  return t('groupIndustry.overview.summary.items', { items: project.items.length, runs: totalRuns })
}

function reasonLabel(reason: string): string {
  const key = `groupIndustry.overview.flowReason.${reason}`
  return te(key) ? t(key) : reason
}

function flowCellOf(project: GroupProject, column: FlowColumn): FlowCell {
  const overview = overviewOf(project)
  if (overview === undefined) {
    return { text: t('groupIndustry.overview.unknown'), title: props.overviewError, active: false }
  }
  if (overview.flow === null) {
    const reason = overview.flowReason ?? ''
    const withoutFlow = FLOW_REASONS_WITHOUT_FLOW.includes(reason)
    return { text: t(withoutFlow ? 'groupIndustry.overview.notStarted' : 'groupIndustry.overview.unknown'), title: reasonLabel(reason), active: false }
  }
  const count = overview.flow[column]
  const unknownCards = overview.flowUnknown > 0 ? ` ${t('groupIndustry.overview.unknownCards', { count: overview.flowUnknown })}` : ''
  return { text: `${count}${unknownCards}`, title: null, active: count > 0 }
}

function isClosed(project: GroupProject): boolean {
  return project.status === 'completed'
}

function awaitedOf(project: GroupProject): ProjectOverview['awaited'] | null {
  const overview = overviewOf(project)
  return overview === undefined ? null : overview.awaited
}
</script>

<template>
  <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/80">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wider text-slate-500">
          <th class="px-4 py-3 font-medium">{{ t('groupIndustry.overview.columns.project') }}</th>
          <th class="px-3 py-3 font-medium">{{ t('groupIndustry.overview.columns.you') }}</th>
          <th class="px-3 py-3 font-medium">{{ t('groupIndustry.overview.columns.team') }}</th>
          <th v-for="column in FLOW_COLUMNS" :key="column" class="px-2 py-3 text-center font-medium">{{ t(`groupIndustry.overview.columns.${column}`) }}</th>
          <th class="px-4 py-3 font-medium">{{ t('groupIndustry.overview.columns.awaited') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="project in projects"
          :key="project.id"
          :data-project="project.id"
          class="cursor-pointer border-b border-slate-800/60 last:border-b-0 hover:bg-slate-800/40"
          tabindex="0"
          @click="emit('open', project)"
          @keydown.enter="emit('open', project)"
        >
          <td data-column="project" class="px-4 py-3">
            <div class="flex items-center gap-3">
              <img
                v-if="project.items.length > 0"
                :src="getTypeIconUrl(project.items[0].typeId, 32)"
                :alt="project.items[0].typeName"
                class="h-8 w-8 flex-shrink-0 rounded border border-slate-700 bg-slate-800"
                @error="onImageError"
              />
              <div class="min-w-0">
                <div class="truncate font-medium text-slate-100" :title="groupProjectLabel(project)">{{ groupProjectLabel(project) }}</div>
                <div class="text-xs text-slate-500">{{ summaryOf(project) }}</div>
              </div>
            </div>
          </td>
          <td data-column="role" class="px-3 py-3">
            <span class="rounded border px-1.5 text-xs" :class="roleOf(project) === 'owner' ? 'border-cyan-500/40 text-cyan-400' : 'border-slate-700 text-slate-400'">{{ roleLabel(project) }}</span>
          </td>
          <td data-column="team" class="px-3 py-3 text-slate-400">{{ teamLabel(project) }}</td>
          <td
            v-for="column in FLOW_COLUMNS"
            :key="column"
            :data-column="column"
            :title="flowCellOf(project, column).title ?? undefined"
            class="px-2 py-3 text-center"
            :class="flowCellOf(project, column).active ? 'flow-cell-active' : 'flow-cell-empty'"
          >
            <span
              class="inline-block min-w-8 rounded border px-2 py-0.5 font-mono text-xs"
              :class="flowCellOf(project, column).active ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300' : 'border-slate-800 text-slate-600'"
            >{{ flowCellOf(project, column).text }}</span>
          </td>
          <td data-column="awaited" class="px-4 py-3">
            <span v-if="isClosed(project)" class="text-emerald-400">{{ t('groupIndustry.overview.closed') }}</span>
            <span v-else-if="awaitedOf(project) === null" class="text-slate-500" :title="overviewError ?? undefined">{{ t('groupIndustry.overview.unknown') }}</span>
            <span v-else-if="awaitedOf(project)?.length === 0" class="text-slate-500">{{ t('groupIndustry.overview.esi') }}</span>
            <template v-else>
              <template v-for="(entry, index) in awaitedOf(project)" :key="entry.name">
                <span v-if="index > 0" class="text-slate-600">{{ AWAITED_SEPARATOR }}</span>
                <span :class="entry.mine ? 'awaited-mine text-amber-400' : 'text-slate-400'">{{ entry.mine ? t('groupIndustry.overview.you') : entry.name }} {{ entry.count }}</span>
              </template>
            </template>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
