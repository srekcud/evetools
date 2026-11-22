<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import TodoPlaceGroup from '@/components/industry-todo/TodoPlaceGroup.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import { useGroupPlaceActionsStore } from '@/stores/group-industry/placeActions'
import { useSyncStore } from '@/stores/sync'
import { groupActionsByPlace } from '@/utils/todoPlaces'

const { t } = useI18n()
const placeActionsStore = useGroupPlaceActionsStore()
const syncStore = useSyncStore()

const groups = computed(() => groupActionsByPlace(placeActionsStore.actions))
const projectCount = computed(() => new Set(placeActionsStore.actions.map(action => action.projectId)).size)
const hasActions = computed(() => placeActionsStore.actions.length > 0)
const isLoadingFirstRead = computed(() => placeActionsStore.loading && !hasActions.value)
const isEmpty = computed(() => !placeActionsStore.loading && placeActionsStore.error === null && !hasActions.value)
const summary = computed(() => t('industryTodo.summary', {
  actions: t('industryTodo.actionsCount', placeActionsStore.actions.length),
  projects: t('industryTodo.projectsCount', projectCount.value),
}))

onMounted(() => {
  void placeActionsStore.fetchAll()
})

watch(() => syncStore.getSyncProgress('industry-project'), () => {
  void placeActionsStore.fetchAll()
})
</script>

<template>
  <MainLayout>
    <IndustryModuleTabs active="todo" />
    <div data-testid="todo-page" class="max-w-3xl">
      <ErrorBanner v-if="placeActionsStore.error" :message="placeActionsStore.error" />
      <div class="border border-slate-800 bg-slate-950">
        <div class="flex items-center gap-2 border-b border-slate-800 px-3 py-2.5">
          <h1 class="font-display text-base font-semibold tracking-wide text-slate-100">{{ t('industryTodo.title') }}</h1>
          <span v-if="hasActions" class="text-xs text-slate-400">{{ summary }}</span>
        </div>
        <p v-if="isLoadingFirstRead" data-testid="todo-loading" class="px-3 py-6 text-sm text-slate-400">{{ t('industryTodo.loading') }}</p>
        <p v-else-if="isEmpty" data-testid="todo-empty" class="px-3 py-6 text-sm text-slate-400">{{ t('industryTodo.empty') }}</p>
        <TodoPlaceGroup v-for="group in groups" :key="group.key" :group="group" />
      </div>
    </div>
  </MainLayout>
</template>
