<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useNavigationEntries, type NavigationEntry } from '@/composables/useNavigationEntries'
import { useCommandPaletteProjects, type ProjectEntry } from '@/composables/useCommandPaletteProjects'
import { useCommandPaletteTypeSearch } from '@/composables/useCommandPaletteTypeSearch'
import { readHiddenModules } from '@/constants/sidebarNavigation'
import type { SearchResult } from '@/stores/industry/types'
import { searchNavigationEntries, normalizeSearchText } from '@/utils/commandPaletteSearch'
import { listTypeActions, typeActionForClick, typeActionForEvent, type TypeAction } from '@/utils/commandPaletteTypeActions'

const INDUSTRY_MODULE = 'industry'
const GROUP_INDUSTRY_MODULE = 'group-industry'

const props = defineProps<{
  show: boolean
  isAdmin: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const router = useRouter()
const { listNavigationEntries } = useNavigationEntries()

const listboxId = useId()
const navigationGroupLabelId = useId()
const projectsGroupLabelId = useId()
const typesGroupLabelId = useId()
const searchInput = ref<HTMLInputElement | null>(null)
const query = ref('')
const activeIndex = ref(0)
const navigationEntries = ref<NavigationEntry[]>([])
const typeActions = ref<TypeAction[]>([])
const isTypeSearchEnabled = computed(() => props.show && typeActions.value.length > 0)

const { searchProjects, unavailableNotices: unavailableProjectNotices, isLoading: areProjectsLoading, loadProjects } = useCommandPaletteProjects()
const { status: typeSearchStatus, results: typeResults } = useCommandPaletteTypeSearch(query, isTypeSearchEnabled)

let elementFocusedBeforeOpening: HTMLElement | null = null
let restoreFocusOnClose = true

type PaletteRow =
  | { kind: 'navigation', entry: NavigationEntry }
  | { kind: 'project', entry: ProjectEntry }
  | { kind: 'type', result: SearchResult }

const navigationRows = computed(() => searchNavigationEntries(navigationEntries.value, query.value))
const projectRows = computed(() => searchProjects(query.value))
const typeRows = computed(() => typeSearchStatus.value === 'ready' ? typeResults.value : [])

const hasQuery = computed(() => normalizeSearchText(query.value) !== '')
const projectNotices = computed((): string[] => {
  if (!hasQuery.value) {
    return []
  }
  const loadingNotices = areProjectsLoading.value ? [t('commandPalette.projects.loading')] : []
  return [...loadingNotices, ...unavailableProjectNotices.value]
})
const typeNotice = computed((): string | null => {
  if (typeSearchStatus.value === 'searching') {
    return t('commandPalette.types.searching')
  }
  return typeSearchStatus.value === 'unavailable' ? t('commandPalette.types.unavailable') : null
})

const projectsOffset = computed(() => navigationRows.value.length)
const typesOffset = computed(() => projectsOffset.value + projectRows.value.length)

const rows = computed((): PaletteRow[] => [
  ...navigationRows.value.map((entry): PaletteRow => ({ kind: 'navigation', entry })),
  ...projectRows.value.map((entry): PaletteRow => ({ kind: 'project', entry })),
  ...typeRows.value.map((result): PaletteRow => ({ kind: 'type', result })),
])
const hasRows = computed(() => rows.value.length > 0)
const showsProjectsGroup = computed(() => projectRows.value.length > 0 || projectNotices.value.length > 0)
const showsTypesGroup = computed(() => typeRows.value.length > 0 || typeNotice.value !== null)
const showsNoResults = computed(() => !showsProjectsGroup.value && !showsTypesGroup.value && !hasRows.value)

function optionId(index: number): string {
  return `${listboxId}-option-${index}`
}

const activeOptionId = computed(() => hasRows.value ? optionId(activeIndex.value) : undefined)

watch(query, () => {
  activeIndex.value = 0
})

watch(() => props.show, async (isShown) => {
  if (isShown) {
    elementFocusedBeforeOpening = document.activeElement instanceof HTMLElement ? document.activeElement : null
    restoreFocusOnClose = true
    query.value = ''
    activeIndex.value = 0
    const hiddenModules = readHiddenModules()
    navigationEntries.value = listNavigationEntries({ isAdmin: props.isAdmin })
    typeActions.value = listTypeActions(hiddenModules)
    void loadProjects({
      industry: !hiddenModules.includes(INDUSTRY_MODULE),
      groupIndustry: !hiddenModules.includes(GROUP_INDUSTRY_MODULE),
    })
    await nextTick()
    searchInput.value?.focus()
    return
  }
  await nextTick()
  if (restoreFocusOnClose) {
    elementFocusedBeforeOpening?.focus()
  }
  elementFocusedBeforeOpening = null
})

async function goTo(route: string): Promise<void> {
  restoreFocusOnClose = false
  emit('close')
  await router.push(route)
}

async function runTypeAction(action: TypeAction, result: SearchResult): Promise<void> {
  await goTo(action.route(result.typeId))
}

async function runClickedTypeRow(result: SearchResult): Promise<void> {
  const action = typeActionForClick(typeActions.value)
  if (action) {
    await runTypeAction(action, result)
  }
}

async function executeActiveRow(event: KeyboardEvent): Promise<void> {
  const row = rows.value[activeIndex.value]
  if (!row) {
    return
  }
  if (row.kind === 'type') {
    const action = typeActionForEvent(typeActions.value, event)
    if (action) {
      await runTypeAction(action, row.result)
    }
    return
  }
  await goTo(row.entry.route)
}

function moveActiveRow(step: 1 | -1): void {
  if (!hasRows.value) {
    return
  }
  activeIndex.value = (activeIndex.value + step + rows.value.length) % rows.value.length
}

function onKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'Escape':
      event.preventDefault()
      event.stopPropagation()
      emit('close')
      break
    case 'Tab':
      event.preventDefault()
      event.stopPropagation()
      searchInput.value?.focus()
      break
    case 'ArrowDown':
      event.preventDefault()
      moveActiveRow(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      moveActiveRow(-1)
      break
    case 'Enter':
      event.preventDefault()
      void executeActiveRow(event)
      break
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-[60] flex items-start justify-center px-4 pt-[12vh]">
      <div
        data-testid="command-palette-backdrop"
        class="absolute inset-0 bg-slate-950/80"
        @click="emit('close')"
      ></div>
      <div
        role="dialog"
        aria-modal="true"
        :aria-label="t('commandPalette.title')"
        class="relative w-full max-w-xl bg-slate-900 border border-cyan-500/30 rounded-xl shadow-2xl shadow-cyan-500/10 overflow-hidden"
        @keydown="onKeydown"
      >
        <input
          ref="searchInput"
          v-model="query"
          type="text"
          role="combobox"
          autocomplete="off"
          spellcheck="false"
          aria-autocomplete="list"
          :aria-label="t('commandPalette.title')"
          :aria-expanded="hasRows"
          :aria-controls="listboxId"
          :aria-activedescendant="activeOptionId"
          :placeholder="t('commandPalette.placeholder')"
          class="w-full bg-transparent px-4 py-3 text-slate-100 placeholder-slate-500 border-b border-slate-800 focus:outline-hidden"
        />
        <div :id="listboxId" role="listbox" :aria-label="t('commandPalette.title')" class="max-h-[50vh] overflow-y-auto" @mousedown.prevent>
          <div v-if="navigationRows.length > 0" role="group" :aria-labelledby="navigationGroupLabelId" class="py-2">
            <div :id="navigationGroupLabelId" class="px-4 py-1 text-xs font-medium text-slate-500 uppercase tracking-wider">
              {{ t('commandPalette.groups.navigation') }}
            </div>
            <div
              v-for="(entry, index) in navigationRows"
              :id="optionId(index)"
              :key="entry.route"
              role="option"
              :aria-selected="index === activeIndex"
              :class="[
                'px-4 py-2 cursor-pointer text-sm',
                index === activeIndex ? 'bg-cyan-500/20 text-cyan-100' : 'text-slate-300'
              ]"
              @mouseenter="activeIndex = index"
              @click="goTo(entry.route)"
            >
              {{ entry.label }}
            </div>
          </div>
          <div v-if="showsProjectsGroup" role="group" :aria-labelledby="projectsGroupLabelId" class="py-2">
            <div :id="projectsGroupLabelId" class="px-4 py-1 text-xs font-medium text-slate-500 uppercase tracking-wider">
              {{ t('commandPalette.groups.projects') }}
            </div>
            <div
              v-for="(project, projectIndex) in projectRows"
              :id="optionId(projectsOffset + projectIndex)"
              :key="project.key"
              role="option"
              :aria-selected="projectsOffset + projectIndex === activeIndex"
              :class="[
                'flex items-center gap-2 px-4 py-2 cursor-pointer text-sm',
                projectsOffset + projectIndex === activeIndex ? 'bg-cyan-500/20 text-cyan-100' : 'text-slate-300'
              ]"
              @mouseenter="activeIndex = projectsOffset + projectIndex"
              @click="goTo(project.route)"
            >
              <span class="truncate">{{ project.label }}</span>
              <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-slate-800 text-slate-400 rounded-sm">
                {{ project.origin === 'industry' ? t('nav.industry') : t('nav.groupIndustry') }}
              </span>
              <span
                v-if="project.isCompleted"
                class="shrink-0 text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm"
              >
                {{ t('commandPalette.projects.completed') }}
              </span>
            </div>
            <div v-for="notice in projectNotices" :key="notice" class="px-4 py-2 text-sm text-slate-500">
              {{ notice }}
            </div>
          </div>
          <div v-if="showsTypesGroup" role="group" :aria-labelledby="typesGroupLabelId" class="py-2">
            <div :id="typesGroupLabelId" class="px-4 py-1 text-xs font-medium text-slate-500 uppercase tracking-wider">
              {{ t('commandPalette.groups.types') }}
            </div>
            <div
              v-for="(result, typeIndex) in typeRows"
              :id="optionId(typesOffset + typeIndex)"
              :key="result.typeId"
              role="option"
              :aria-selected="typesOffset + typeIndex === activeIndex"
              :class="[
                'px-4 py-2 cursor-pointer text-sm',
                typesOffset + typeIndex === activeIndex ? 'bg-cyan-500/20 text-cyan-100' : 'text-slate-300'
              ]"
              @mouseenter="activeIndex = typesOffset + typeIndex"
              @click="runClickedTypeRow(result)"
            >
              <span>{{ result.typeName }}</span>
              <div v-if="typesOffset + typeIndex === activeIndex" class="mt-1.5 flex flex-wrap gap-2">
                <button
                  v-for="action in typeActions"
                  :key="action.id"
                  type="button"
                  tabindex="-1"
                  class="flex items-center gap-1.5 rounded-sm bg-slate-800 px-2 py-1 text-xs text-slate-200 hover:bg-slate-700"
                  @click.stop="runTypeAction(action, result)"
                >
                  <span>{{ t(`commandPalette.types.actions.${action.id}`) }}</span>
                  <kbd class="font-mono text-[10px] text-slate-400">{{ action.key }}</kbd>
                </button>
              </div>
            </div>
            <div v-if="typeNotice" class="px-4 py-2 text-sm text-slate-500">
              {{ typeNotice }}
            </div>
          </div>
        </div>
        <p v-if="showsNoResults" role="status" class="px-4 py-6 text-center text-sm text-slate-500">
          {{ t('commandPalette.noResults', { query }) }}
        </p>
      </div>
    </div>
  </Teleport>
</template>
