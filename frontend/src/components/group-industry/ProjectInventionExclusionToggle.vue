<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'

const props = defineProps<{
  projectId: string
  status: string
  canEdit: boolean
  excludesInvention: boolean
}>()

const emit = defineEmits<{
  saved: []
}>()

const { t } = useI18n()
const projectStore = useGroupProjectStore()

const checked = ref(props.excludesInvention)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

const isEditable = computed(() => props.canEdit && props.status === 'draft')

watch(() => props.excludesInvention, value => {
  checked.value = value
})

async function save(): Promise<void> {
  const requested = checked.value
  isSaving.value = true
  errorMessage.value = null
  try {
    await projectStore.updateProject(props.projectId, { excludesInvention: requested })
    emit('saved')
  } catch (e) {
    checked.value = props.excludesInvention
    errorMessage.value = e instanceof Error ? e.message : t('groupIndustry.inventionExclusion.saveError')
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section
    data-testid="project-invention-exclusion"
    class="mb-4 bg-slate-900/80 rounded-xl border border-slate-800 px-4 py-3"
  >
    <label class="flex items-center gap-2.5" :class="isEditable ? 'cursor-pointer' : 'cursor-default'">
      <input
        v-model="checked"
        type="checkbox"
        data-testid="project-invention-exclusion-toggle"
        :disabled="!isEditable || isSaving"
        class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 disabled:opacity-60"
        @change="save"
      />
      <span class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.inventionExclusion.label') }}
      </span>
    </label>
    <p class="text-xs text-slate-500 mt-1 ml-6">{{ t('groupIndustry.inventionExclusion.help') }}</p>

    <p
      v-if="errorMessage !== null"
      data-testid="project-invention-exclusion-error"
      role="alert"
      class="mt-3 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300 break-words"
    >{{ errorMessage }}</p>
  </section>
</template>
