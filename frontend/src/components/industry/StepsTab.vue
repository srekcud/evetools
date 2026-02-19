<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useStepsStore } from '@/stores/industry/steps'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import StepTree from './StepTree.vue'
import StepHierarchyTree from './StepHierarchyTree.vue'

const { t } = useI18n()

const props = defineProps<{
  projectId: string
}>()

const store = useIndustryStore()
const stepsStore = useStepsStore()
const syncStore = useSyncStore()
const { formatIsk, formatDateTime } = useFormatters()

const stepsViewMode = ref<'flat' | 'tree'>('flat')
const regeneratingSteps = ref(false)
const matchJobsLoading = ref(false)
const matchJobsWarning = ref<string | null>(null)
const showAvailableJobs = ref(false)

// Mercure sync progress
const industryProjectProgress = computed(() => syncStore.getSyncProgress('industry-project'))

watch(industryProjectProgress, (progress) => {
  if (progress?.status === 'completed') {
    store.fetchProject(props.projectId)
    regeneratingSteps.value = false
  }
})

async function togglePurchased(stepId: string, purchased: boolean) {
  await store.toggleStepPurchased(props.projectId, stepId, purchased)
}

async function matchJobs() {
  matchJobsLoading.value = true
  matchJobsWarning.value = null
  try {
    const warning = await store.matchJobs(props.projectId)
    matchJobsWarning.value = warning
    await Promise.all([
      store.fetchProject(props.projectId),
      stepsStore.fetchAvailableJobs(props.projectId),
    ])
  } finally {
    matchJobsLoading.value = false
  }
}

async function deleteStepHandler(stepId: string) {
  await store.deleteStep(props.projectId, stepId)
}

async function addChildJobHandler(splitGroupId: string | null, stepId: string | null, runs: number) {
  await store.addChildJob(props.projectId, splitGroupId, stepId, runs)
}

async function updateStepRuns(stepId: string, runs: number) {
  await store.updateStepRuns(props.projectId, stepId, runs)
}

async function updateStepMe(stepId: string, meLevel: number) {
  await stepsStore.updateStepMeLevel(props.projectId, stepId, meLevel)
}

async function updateStepTe(stepId: string, teLevel: number) {
  await stepsStore.updateStepTeLevel(props.projectId, stepId, teLevel)
}

async function splitStepHandler(stepId: string, numberOfJobs: number) {
  await stepsStore.splitStep(props.projectId, stepId, numberOfJobs)
}

async function mergeStepsHandler(stepId: string) {
  await stepsStore.mergeSteps(props.projectId, stepId)
}

async function toggleAvailableJobs() {
  showAvailableJobs.value = !showAvailableJobs.value
  if (showAvailableJobs.value) {
    await stepsStore.fetchAvailableJobs(props.projectId)
  }
}

async function linkJobToStep(stepId: string, esiJobId: number) {
  await stepsStore.linkJob(props.projectId, stepId, esiJobId)
}

async function unlinkJobMatch(matchId: string) {
  await stepsStore.unlinkJob(props.projectId, matchId)
}

// Get compatible steps for a given blueprint type ID
function compatibleSteps(blueprintTypeId: number) {
  return store.currentProject?.steps?.filter(
    s => s.blueprintTypeId === blueprintTypeId && s.activityType !== 'copy'
  ) ?? []
}

defineExpose({
  matchJobsLoading,
  matchJobsWarning,
  matchJobs,
  togglePurchased,
  showAvailableJobs,
})
</script>

<template>
  <div>
    <!-- ESI Warning -->
    <div
      v-if="matchJobsWarning"
      class="mb-4 p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg flex items-center gap-3"
    >
      <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <span class="text-amber-200 text-sm">{{ matchJobsWarning }}</span>
      <button
        @click="matchJobsWarning = null"
        class="ml-auto p-1 hover:bg-amber-500/30 rounded-sm text-amber-400"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Toolbar: BPC Kit + Available Jobs + View toggle -->
    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-4">
        <button
          v-if="store.currentProject?.status !== 'completed'"
          @click="toggleAvailableJobs"
          :class="[
            'flex items-center gap-2 text-sm px-3 py-1.5 rounded-lg border transition-colors',
            showAvailableJobs
              ? 'bg-cyan-600/20 border-cyan-500/50 text-cyan-400'
              : 'bg-slate-800 border-slate-700 text-slate-400 hover:text-slate-200'
          ]"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          {{ t('industry.stepsTab.esiJobs') }}
        </button>
      </div>

      <!-- View mode toggle -->
      <div class="flex items-center gap-1 bg-slate-800 rounded-lg p-1">
        <button
          @click="stepsViewMode = 'flat'"
          :class="[
            'px-3 py-1.5 text-xs font-medium rounded-sm transition-colors',
            stepsViewMode === 'flat'
              ? 'bg-cyan-600 text-white'
              : 'text-slate-400 hover:text-slate-200'
          ]"
        >
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            {{ t('industry.stepsTab.list') }}
          </span>
        </button>
        <button
          @click="stepsViewMode = 'tree'"
          :class="[
            'px-3 py-1.5 text-xs font-medium rounded-sm transition-colors',
            stepsViewMode === 'tree'
              ? 'bg-cyan-600 text-white'
              : 'text-slate-400 hover:text-slate-200'
          ]"
        >
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h4v4H3V4zm0 8h4v4H3v-4zm0 8h4v4H3v-4zm8-16h10M11 12h10M11 20h10" />
            </svg>
            {{ t('industry.stepsTab.tree') }}
          </span>
        </button>
      </div>
    </div>

    <!-- Available ESI Jobs Panel -->
    <div v-if="showAvailableJobs" class="mb-4 eve-card p-4">
      <div class="flex items-center justify-between mb-3">
        <h4 class="text-sm font-semibold text-slate-300 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          {{ t('industry.stepsTab.availableEsiJobs') }}
          <span class="text-xs text-slate-500">({{ stepsStore.availableJobs.length }})</span>
        </h4>
        <button
          @click="matchJobs"
          :disabled="matchJobsLoading"
          class="px-3 py-1.5 bg-cyan-500/20 border border-cyan-500/50 text-cyan-400 rounded-lg text-xs font-medium flex items-center gap-1.5 hover:bg-cyan-500/30 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          <svg v-if="matchJobsLoading" class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ matchJobsLoading ? t('common.actions.syncing') : t('industry.stepsTab.syncJobs') }}
        </button>
      </div>

      <div v-if="stepsStore.availableJobsLoading && stepsStore.availableJobs.length === 0" class="text-center py-4 text-slate-500 text-sm">
        {{ t('common.status.loading') }}
      </div>
      <div v-else-if="stepsStore.availableJobs.length === 0" class="text-center py-4 text-slate-500 text-sm">
        {{ t('industry.stepsTab.noEsiJobs') }}
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700">
              <th class="text-left py-2 px-2">{{ t('industry.stepsTab.character') }}</th>
              <th class="text-left py-2 px-2">{{ t('industry.table.product') }}</th>
              <th class="text-right py-2 px-2">Runs</th>
              <th class="text-left py-2 px-2">{{ t('industry.stepsTab.status') }}</th>
              <th class="text-left py-2 px-2">{{ t('industry.stepsTab.start') }}</th>
              <th class="text-right py-2 px-2">{{ t('industry.stepsTab.cost') }}</th>
              <th class="text-left py-2 px-2">{{ t('industry.stepsTab.linkedTo') }}</th>
              <th class="text-center py-2 px-2">{{ t('industry.stepsTab.action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="job in stepsStore.availableJobs"
              :key="job.esiJobId"
              class="border-b border-slate-800 hover:bg-slate-800/50"
            >
              <td class="py-2 px-2 text-slate-300">{{ job.characterName }}</td>
              <td class="py-2 px-2 text-slate-200">{{ job.productTypeName }}</td>
              <td class="py-2 px-2 text-right font-mono text-slate-300">{{ job.runs }}</td>
              <td class="py-2 px-2">
                <span :class="[
                  'text-xs px-1.5 py-0.5 rounded-sm',
                  job.status === 'active' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-emerald-500/20 text-emerald-400'
                ]">
                  {{ job.status === 'active' ? t('industry.stepStatus.active') : t('industry.stepStatus.completed') }}
                </span>
              </td>
              <td class="py-2 px-2 text-xs text-slate-500">{{ formatDateTime(job.startDate) }}</td>
              <td class="py-2 px-2 text-right font-mono text-slate-400">{{ formatIsk(job.cost) }}</td>
              <td class="py-2 px-2">
                <span v-if="job.linkedToStepId" class="text-xs text-blue-400">
                  {{ job.linkedToStepName }}
                </span>
                <span v-else class="text-xs text-slate-600">—</span>
              </td>
              <td class="py-2 px-2 text-center">
                <!-- Linked: show unlink button -->
                <button
                  v-if="job.matchId"
                  @click="unlinkJobMatch(job.matchId!)"
                  class="px-2 py-1 text-xs bg-red-500/20 text-red-400 hover:bg-red-500/30 rounded-sm border border-red-500/30"
                  :title="t('industry.stepsTab.unlink')"
                >
                  {{ t('industry.stepsTab.unlink') }}
                </button>
                <!-- Not linked: show dropdown to link -->
                <select
                  v-else-if="compatibleSteps(job.blueprintTypeId).length > 0"
                  @change="(e) => { const val = (e.target as HTMLSelectElement).value; if (val) linkJobToStep(val, job.esiJobId); (e.target as HTMLSelectElement).value = '' }"
                  class="bg-slate-800 border border-slate-700 rounded-sm px-2 py-1 text-xs text-slate-300 focus:outline-hidden focus:border-cyan-500"
                >
                  <option value="">{{ t('industry.stepsTab.linkToStep') }}</option>
                  <option
                    v-for="step in compatibleSteps(job.blueprintTypeId)"
                    :key="step.id"
                    :value="step.id"
                  >
                    {{ step.productTypeName }} ({{ step.runs }} runs)
                  </option>
                </select>
                <span v-else class="text-xs text-slate-600">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Steps list (flat view) -->
    <div v-if="stepsViewMode === 'flat'">
      <div v-if="store.currentProject?.steps && store.currentProject.steps.length > 0">
        <StepTree
          :steps="store.currentProject.steps"
          :readonly="store.currentProject.status === 'completed'"
          @toggle-purchased="togglePurchased"
          @update-step-runs="updateStepRuns"
          @update-step-me="updateStepMe"
          @update-step-te="updateStepTe"
          @delete-step="deleteStepHandler"
          @add-child-job="addChildJobHandler"
          @split-step="splitStepHandler"
          @merge-steps="mergeStepsHandler"
          @unlink-job="unlinkJobMatch"
        />
      </div>
      <div v-else class="text-center py-8 text-slate-500">
        {{ t('industry.stepsTab.noSteps') }}
      </div>
    </div>

    <!-- Steps tree (hierarchy view) -->
    <div v-else-if="stepsViewMode === 'tree'">
      <div v-if="store.currentProject?.tree && store.currentProject?.steps">
        <StepHierarchyTree
          :tree="store.currentProject.tree"
          :steps="store.currentProject.steps"
          :readonly="store.currentProject.status === 'completed'"
          @toggle-purchased="togglePurchased"
        />
      </div>
      <div v-else class="text-center py-8 text-slate-500">
        {{ t('industry.stepsTab.treeUnavailable') }}
      </div>
    </div>
  </div>
</template>
