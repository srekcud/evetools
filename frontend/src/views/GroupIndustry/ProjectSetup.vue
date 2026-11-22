<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import ProjectSetupSourcing from '@/components/group-industry/ProjectSetupSourcing.vue'
import ProjectSetupStations from '@/components/group-industry/ProjectSetupStations.vue'
import ProjectSetupStepBar from '@/components/group-industry/ProjectSetupStepBar.vue'
import { projectDisplayNameOf } from '@/components/group-industry/projectDisplayName'
import {
  blacklistAfter,
  marketsToWrite,
  sourcingQuery,
  stationsPayload,
  type SourcingPicks,
  type StationPicks,
} from '@/components/group-industry/projectSetup'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useProjectSetupStore } from '@/stores/group-industry/projectSetup'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const projectStore = useGroupProjectStore()
const setupStore = useProjectSetupStore()

const projectId = computed(() => route.params.id as string)
const step = ref<2 | 3>(2)
const isReady = ref(false)
const stationPicks = ref<StationPicks>({})
const sourcingPicks = ref<SourcingPicks>({})

const project = computed(() => (projectStore.currentProject?.id === projectId.value ? projectStore.currentProject : null))

const stationNames = computed<Record<string, string>>(() => {
  const names: Record<string, string> = {}
  for (const type of setupStore.workStations?.workTypes ?? []) {
    for (const row of type.rows) {
      if (row.name !== null) names[row.structureId] = row.name
    }
  }
  return names
})

function openProjectPage(): void {
  void router.push({ name: 'industry-project', params: { id: projectId.value } })
}

function canRunSetup(): boolean {
  const current = project.value
  if (current === null) return false
  return (current.myRole === 'owner' || current.myRole === 'admin') && current.status === 'draft'
}

onMounted(async () => {
  await projectStore.fetchProjectDetail(projectId.value)
  if (!canRunSetup()) {
    void router.replace({ name: 'industry-project', params: { id: projectId.value } })
    return
  }
  isReady.value = true
  await Promise.all([
    setupStore.fetchWorkStations(projectId.value),
    setupStore.fetchSourcingDecisions(projectId.value),
  ])
})

async function updateSourcingPicks(picks: SourcingPicks): Promise<void> {
  sourcingPicks.value = picks
  await setupStore.fetchSourcingDecisions(projectId.value, sourcingQuery(setupStore.decisions?.rows ?? [], picks))
}

async function validate(): Promise<void> {
  const current = project.value
  const workStations = setupStore.workStations
  const decisions = setupStore.decisions
  if (current === null || workStations === null || decisions === null) return
  const saved = await setupStore.saveSetup(projectId.value, {
    workStations: stationsPayload(workStations.workTypes, stationPicks.value),
    blacklistTypeIds: blacklistAfter(current.blacklistTypeIds, decisions.rows, sourcingPicks.value),
    markets: marketsToWrite(decisions.rows, sourcingPicks.value),
  })
  if (saved) openProjectPage()
}
</script>

<template>
  <MainLayout>
    <LoadingSpinner v-if="!isReady && projectStore.loading" />
    <div v-else-if="isReady && project" data-testid="project-setup">
      <IndustryModuleTabs active="projects" />

      <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-400">
        <router-link :to="{ name: 'industry-projects' }" class="hover:text-slate-200">{{ t('groupIndustry.setup.crumbProjects') }}</router-link>
        <span>›</span>
        <span class="text-slate-200">{{ projectDisplayNameOf(project) }}</span>
        <span class="flex-1" />
        <button type="button" data-testid="setup-skip" class="text-slate-400 underline hover:text-slate-200" @click="openProjectPage">
          {{ t('groupIndustry.setup.skip') }}
        </button>
      </div>

      <ProjectSetupStepBar :step="step" :busy="setupStore.saving" @next="step = 3" @validate="validate" @goto="step = $event" />

      <p v-if="setupStore.saveError" data-testid="setup-save-error" class="mb-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300">
        {{ t('groupIndustry.setup.saveFailed', { message: setupStore.saveError }) }}
      </p>

      <template v-if="step === 2">
        <p v-if="setupStore.workStationsError" class="mb-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300">
          {{ t('groupIndustry.setup.stations.loadFailed', { message: setupStore.workStationsError }) }}
        </p>
        <ProjectSetupStations
          v-else-if="setupStore.workStations"
          v-model:picks="stationPicks"
          :work-stations="setupStore.workStations"
          @next="step = 3"
        />
        <LoadingSpinner v-else />
      </template>

      <template v-else>
        <p v-if="setupStore.decisionsError" class="mb-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300">
          {{ t('groupIndustry.setup.sourcing.loadFailed', { message: setupStore.decisionsError }) }}
        </p>
        <ProjectSetupSourcing
          v-else-if="setupStore.decisions"
          :decisions="setupStore.decisions"
          :picks="sourcingPicks"
          :baseline-total="setupStore.baselineTotal"
          :station-names="stationNames"
          @update:picks="updateSourcingPicks"
          @back="step = 2"
          @validate="validate"
        />
        <LoadingSpinner v-else />
      </template>
    </div>
  </MainLayout>
</template>
