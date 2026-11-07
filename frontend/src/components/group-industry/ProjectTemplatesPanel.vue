<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useProjectTemplatesStore } from '@/stores/group-industry/templates'
import type { ProjectTemplate } from '@/stores/group-industry/types'

const { t } = useI18n()
const store = useProjectTemplatesStore()

defineEmits<{
  use: [template: ProjectTemplate]
}>()

const templateToDelete = ref<ProjectTemplate | null>(null)
const isDeleting = ref(false)
const sharingTemplateId = ref<string | null>(null)

onMounted(() => store.fetchTemplates())

async function succeeded(action: () => Promise<unknown>): Promise<boolean> {
  try {
    await action()
    return true
  } catch {
    return false
  }
}

async function toggleSharing(template: ProjectTemplate): Promise<void> {
  sharingTemplateId.value = template.id
  await succeeded(() => store.updateTemplate(template.id, { sharedWithCorporation: !template.sharedWithCorporation }))
  sharingTemplateId.value = null
}

async function confirmDelete(): Promise<void> {
  if (!templateToDelete.value) return
  const templateId = templateToDelete.value.id
  isDeleting.value = true
  await succeeded(() => store.deleteTemplate(templateId))
  templateToDelete.value = null
  isDeleting.value = false
}
</script>

<template>
  <div data-testid="project-templates-panel">
    <p v-if="store.error" role="alert" class="mb-3 text-sm text-red-400">{{ store.error }}</p>

    <div v-if="store.loading" class="flex justify-center py-8">
      <LoadingSpinner />
    </div>

    <p
      v-else-if="store.templates.length === 0 && !store.error"
      data-testid="project-templates-empty"
      class="py-8 text-center text-sm text-slate-500"
    >
      {{ t('groupIndustry.templates.empty') }}
    </p>

    <div v-else-if="store.templates.length > 0" class="overflow-x-auto border border-slate-800 rounded-lg">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
            <th class="text-left py-2.5 px-3">{{ t('groupIndustry.templates.templateColumn') }}</th>
            <th class="text-center py-2.5 px-2">{{ t('groupIndustry.templates.itemsColumn') }}</th>
            <th class="text-center py-2.5 px-2">{{ t('groupIndustry.templates.sharedColumn') }}</th>
            <th class="py-2.5 px-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/30">
          <tr
            v-for="template in store.templates"
            :key="template.id"
            data-testid="project-template-row"
            class="hover:bg-slate-800/30"
          >
            <td class="py-2.5 px-3 max-w-[14rem]">
              <p
                data-testid="project-template-name"
                class="truncate font-medium text-slate-200"
                :title="template.name"
              >{{ template.name }}</p>
              <p
                data-testid="project-template-owner"
                class="truncate text-xs text-slate-500"
                :title="template.ownerName ?? t('common.unknownName')"
              >{{ t('groupIndustry.templates.byOwner', { owner: template.ownerName ?? t('common.unknownName') }) }}</p>
            </td>
            <td data-testid="project-template-items" class="py-2.5 px-2 text-center font-mono text-slate-300">
              {{ template.items.length }}
            </td>
            <td class="py-2.5 px-2 text-center">
              <span
                data-testid="project-template-scope"
                :data-scope="template.sharedWithCorporation ? 'corporation' : 'personal'"
                class="text-xs px-2 py-0.5 rounded border font-medium"
                :class="template.sharedWithCorporation
                  ? 'bg-cyan-500/15 text-cyan-400 border-cyan-500/20'
                  : 'bg-slate-700 text-slate-400 border-slate-600'"
              >{{ template.sharedWithCorporation ? t('groupIndustry.templates.scopeCorporation') : t('groupIndustry.templates.scopePersonal') }}</span>
            </td>
            <td class="py-2.5 px-3">
              <div class="flex items-center justify-end gap-1.5">
                <button
                  v-if="template.isOwn"
                  type="button"
                  role="switch"
                  data-testid="project-template-share-toggle"
                  :aria-checked="template.sharedWithCorporation"
                  :aria-label="template.sharedWithCorporation ? t('groupIndustry.templates.stopSharing') : t('groupIndustry.templates.shareWithCorporation')"
                  :title="template.sharedWithCorporation ? t('groupIndustry.templates.stopSharing') : t('groupIndustry.templates.shareWithCorporation')"
                  :disabled="sharingTemplateId === template.id"
                  class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors disabled:opacity-50"
                  :class="template.sharedWithCorporation ? 'text-cyan-400' : 'text-slate-500'"
                  @click="toggleSharing(template)"
                >
                  <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </button>
                <button
                  v-if="template.isOwn"
                  type="button"
                  data-testid="project-template-delete"
                  :aria-label="t('common.actions.delete')"
                  :title="t('common.actions.delete')"
                  class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors"
                  @click="templateToDelete = template"
                >
                  <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
                <button
                  type="button"
                  data-testid="project-template-use"
                  class="px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-medium transition-colors"
                  @click="$emit('use', template)"
                >{{ t('groupIndustry.templates.use') }}</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ConfirmModal
      :show="templateToDelete !== null"
      :title="t('groupIndustry.templates.deleteTitle')"
      :message="t('groupIndustry.templates.deleteMessage', { name: templateToDelete?.name ?? '' })"
      :confirm-label="t('common.actions.delete')"
      :is-loading="isDeleting"
      @confirm="confirmDelete"
      @cancel="templateToDelete = null"
    />
  </div>
</template>
