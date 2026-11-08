<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBlacklistStore } from '@/stores/industry/blacklist'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BlacklistCategory } from '@/stores/industry/types'

const props = defineProps<{
  projectId: string
  status: string
  canEdit: boolean
  blacklistGroupIds: number[]
  blacklistTypeIds: number[]
}>()

const emit = defineEmits<{
  saved: []
}>()

const { t } = useI18n()
const blacklistStore = useBlacklistStore()
const projectStore = useGroupProjectStore()

const isOpen = ref(false)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)
const checkedKeys = ref<string[]>([])

const isOffered = computed(() => props.canEdit && props.status === 'draft')
const categories = computed<BlacklistCategory[]>(() => blacklistStore.blacklist?.categories ?? [])
const groupIdsOutsideEveryCategory = computed(() => {
  const groupIdsOfCategories = new Set(categories.value.flatMap(category => category.groupIds))
  return props.blacklistGroupIds.filter(groupId => !groupIdsOfCategories.has(groupId))
})

function isCategoryInTheBlacklist(category: BlacklistCategory): boolean {
  return category.groupIds.every(groupId => props.blacklistGroupIds.includes(groupId))
}

async function open(): Promise<void> {
  errorMessage.value = null
  await blacklistStore.fetchBlacklist()
  checkedKeys.value = categories.value.filter(isCategoryInTheBlacklist).map(category => category.key)
  isOpen.value = true
}

function close(): void {
  isOpen.value = false
  errorMessage.value = null
}

async function save(): Promise<void> {
  isSaving.value = true
  errorMessage.value = null
  try {
    await projectStore.updateProject(props.projectId, {
      blacklistCategoryKeys: checkedKeys.value,
      blacklistGroupIds: groupIdsOutsideEveryCategory.value,
      blacklistTypeIds: props.blacklistTypeIds,
    })
    isOpen.value = false
    emit('saved')
  } catch (e) {
    errorMessage.value = e instanceof Error ? e.message : t('groupIndustry.blacklistEditor.saveError')
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div v-if="isOffered" class="mb-4">
    <button
      v-if="!isOpen"
      type="button"
      data-testid="project-blacklist-edit"
      class="text-xs font-medium text-cyan-400 hover:text-cyan-300 underline-offset-2 hover:underline"
      @click="open"
    >{{ t('groupIndustry.blacklistEditor.edit') }}</button>

    <section
      v-else
      data-testid="project-blacklist-editor"
      class="bg-slate-900/80 rounded-xl border border-slate-800 p-4"
    >
      <h3 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.blacklistEditor.title') }}
      </h3>
      <p class="text-xs text-slate-500 mt-1 mb-3">{{ t('groupIndustry.blacklistEditor.description') }}</p>

      <div class="grid grid-cols-2 gap-x-6 gap-y-2 mb-3">
        <label
          v-for="category in categories"
          :key="category.key"
          class="flex items-center gap-2.5 cursor-pointer"
        >
          <input
            v-model="checkedKeys"
            type="checkbox"
            :value="category.key"
            :data-testid="`project-blacklist-category-${category.key}`"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
          />
          <span class="text-sm text-slate-300">{{ category.label }}</span>
        </label>
      </div>

      <p
        v-if="groupIdsOutsideEveryCategory.length + blacklistTypeIds.length > 0"
        class="text-xs text-slate-500 mb-3"
      >{{ t('groupIndustry.blacklistEditor.kept', { groups: groupIdsOutsideEveryCategory.length, types: blacklistTypeIds.length }) }}</p>

      <p
        v-if="errorMessage !== null"
        data-testid="project-blacklist-error"
        role="alert"
        class="mb-3 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300 break-words"
      >{{ errorMessage }}</p>

      <div class="flex gap-2">
        <button
          type="button"
          data-testid="project-blacklist-save"
          :disabled="isSaving"
          class="px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-sm text-white"
          @click="save"
        >{{ t('groupIndustry.blacklistEditor.save') }}</button>
        <button
          type="button"
          data-testid="project-blacklist-cancel"
          :disabled="isSaving"
          class="px-3 py-1.5 rounded-lg border border-slate-700 text-sm text-slate-300 hover:text-slate-100"
          @click="close"
        >{{ t('groupIndustry.blacklistEditor.cancel') }}</button>
      </div>
    </section>
  </div>
</template>
