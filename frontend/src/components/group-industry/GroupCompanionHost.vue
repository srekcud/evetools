<script setup lang="ts">
import { useEventListener } from '@vueuse/core'
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import { buildCompanionTasks } from '@/components/group-industry/companionTasks'
import { projectDisplayNameOf } from '@/components/group-industry/projectDisplayName'
import {
  COMPANION_WINDOW_SIZES,
  recallCompanionWindowSize,
  rememberCompanionWindowSize,
  type CompanionWindowSizeKey,
} from '@/components/group-industry/companionWindowSize'
import { GROUP_PROJECT_EVENT_COALESCE_MS, useCoalescedGroupProjectEvents } from '@/composables/useCoalescedGroupProjectEvents'
import { useDetachedWindow } from '@/composables/useDetachedWindow'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { useUnassignedJobsStore } from '@/stores/group-industry/unassignedJobs'

const CLOCK_TICK_MS = 30_000
const POSITION_REFRESH_MS = 60_000
const BACKGROUND_RGB = '2 6 23'

const { t } = useI18n()
const router = useRouter()
const companion = useGroupCompanionStore()
const unassignedJobs = useUnassignedJobsStore()
const authStore = useAuthStore()
const { showToast } = useToast()
const detached = useDetachedWindow('group')

const size = ref<CompanionWindowSizeKey>(recallCompanionWindowSize())
const now = ref(Date.now())
const mountPoint = shallowRef<HTMLElement | null>(null)
let clockTimer: ReturnType<typeof setInterval> | null = null
let positionTimer: ReturnType<typeof setInterval> | null = null

const projectName = computed(() => {
  const project = companion.project
  if (project === null) return ''
  return projectDisplayNameOf(project)
})

const tasks = computed(() => {
  const project = companion.project
  if (project === null) return null
  return buildCompanionTasks({
    bomItems: companion.bomItems,
    chainNodes: companion.chainNodes,
    project,
    members: companion.members,
    myCharacterNames: authStore.user === null ? [] : authStore.user.characters.map(character => character.name),
    priceAlert: companion.priceAlert,
    undercutOrders: companion.undercutOrders,
    unassignedJobCount: unassignedJobs.count,
    now: now.value,
    flow: {
      placedActions: companion.placedActions,
      positions: companion.positions,
      transfers: companion.transfers,
      transportLegs: companion.transportLegs,
      stockProposal: companion.stockProposal,
      myEveCharacterIds: authStore.user === null ? [] : authStore.user.characters.map(character => character.eveCharacterId),
    },
  })
})

function noticeText(): string | null {
  const notice = detached.notice.value
  if (notice === null) return null
  const text = t(`groupIndustry.companion.notices.${notice}`)
  return detached.noticeCause.value === null ? text : `${text} (${detached.noticeCause.value})`
}

const shownMessage = computed(() => companion.error ?? noticeText())

function stopClock(): void {
  if (clockTimer !== null) clearInterval(clockTimer)
  clockTimer = null
  if (positionTimer !== null) clearInterval(positionTimer)
  positionTimer = null
}

function isWindowHidden(): boolean {
  return (detached.currentWindow.value?.document ?? document).visibilityState === 'hidden'
}

function startClock(): void {
  stopClock()
  now.value = Date.now()
  clockTimer = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MS)
  positionTimer = setInterval(() => {
    if (!isWindowHidden()) void companion.refreshPlace()
  }, POSITION_REFRESH_MS)
}

function openWindowContent(opened: Window): void {
  const windowDocument = opened.document
  windowDocument.title = t('groupIndustry.companion.windowTitle')
  windowDocument.body.style.margin = '0'
  windowDocument.body.style.backgroundColor = `rgb(${BACKGROUND_RGB})`
  const container = windowDocument.createElement('div')
  container.style.height = '100vh'
  windowDocument.body.appendChild(container)
  mountPoint.value = container
  startClock()
}

function closeWindowContent(): void {
  mountPoint.value?.remove()
  mountPoint.value = null
  stopClock()
}

async function openWindow(): Promise<void> {
  const handle = await detached.open(COMPANION_WINDOW_SIZES[size.value])
  if (handle === null) {
    const refusal = noticeText()
    if (refusal !== null) showToast(refusal, 'warning')
    companion.close()
    return
  }
  if (companion.projectId === null) {
    detached.close()
    return
  }
  openWindowContent(handle.window)
}

function toggleSize(): void {
  size.value = size.value === 'bar' ? 'column' : 'bar'
  rememberCompanionWindowSize(size.value)
  detached.resize(COMPANION_WINDOW_SIZES[size.value].width, COMPANION_WINDOW_SIZES[size.value].height)
}

async function goToProject(query: Record<string, string>): Promise<void> {
  const projectId = companion.projectId
  if (projectId === null) return
  window.focus()
  await router.push({ name: 'industry-project', params: { id: projectId }, query: { tab: 'chain', ...query } })
}

function deliver(lineId: string): Promise<void> {
  return goToProject({ contribute: lineId })
}

function openMembers(): Promise<void> {
  return goToProject({ tab: 'members' })
}

function openBom(): Promise<void> {
  return goToProject({})
}

function openUnassignedJobs(): Promise<unknown> {
  window.focus()
  return router.push({ name: 'industry-projects', query: { tab: 'my-projects' } })
}

watch(
  () => companion.projectId,
  (projectId) => {
    if (projectId === null) {
      detached.close()
      closeWindowContent()
      return
    }
    if (!detached.isOpen.value && !detached.isOpening.value) openWindow()
  },
  { immediate: true },
)

watch(detached.isOpen, (isOpen) => {
  if (isOpen) return
  closeWindowContent()
  companion.close()
})

useCoalescedGroupProjectEvents(
  () => companion.projectId,
  actions => companion.handleEvents(actions),
  GROUP_PROJECT_EVENT_COALESCE_MS,
  () => detached.currentWindow.value?.document ?? document,
)

useEventListener(window, 'pagehide', () => detached.close())

onBeforeUnmount(() => {
  detached.close()
  closeWindowContent()
})
</script>

<template>
  <Teleport v-if="mountPoint !== null" :to="mountPoint">
    <GroupCompanion
      v-if="tasks !== null"
      :tasks="tasks"
      :project-name="projectName"
      :size="size"
      :error-message="shownMessage"
      :projects="companion.projectChoices"
      :current-project-id="companion.projectId"
      @claim="companion.claim"
      @release="companion.release"
      @deliver="deliver"
      @open-bom="openBom"
      @open-unassigned-jobs="openUnassignedJobs"
      @open-members="openMembers"
      @arrive="companion.arriveTransfer"
      @declare-leg="companion.declareLeg"
      @supply="companion.supply"
      @switch-project="companion.switchProject"
      @toggle-size="toggleSize"
    />
    <p v-else class="p-3 font-mono text-sm text-slate-300" role="status">{{ shownMessage ?? t('groupIndustry.companion.loading') }}</p>
  </Teleport>
</template>
