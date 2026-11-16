<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStructuresStore } from '@/stores/industry/structures'
import {
  STRUCTURE_LIST_FORMAT,
  STRUCTURE_LIST_VERSION,
  type StructureImportLine,
  type StructureImportStatus,
  type StructureListEntry,
} from '@/stores/industry/types'

const emit = defineEmits<{ imported: [] }>()

const { t } = useI18n()
const store = useStructuresStore()

const EXPORT_FILE_NAME = 'evetools-structures.json'
const JSON_INDENT = 2

type ParseFailure = 'invalidJson' | 'wrongFormat' | 'unsupportedVersion'

const exportText = ref('')
const exportError = ref(false)
const copied = ref(false)
const copyFailed = ref(false)

const importText = ref('')
const importError = ref<string | null>(null)
const parsedEntries = ref<StructureListEntry[]>([])
const previewLines = ref<StructureImportLine[]>([])
const selectedIndexes = ref<Set<number>>(new Set())
const overwriteExisting = ref(false)
const busy = ref(false)
const resultCounts = ref<Record<StructureImportStatus, number> | null>(null)

const hasPreview = computed(() => previewLines.value.length > 0)
const hasSelection = computed(() => selectedIndexes.value.size > 0)

function parseStructureList(text: string): StructureListEntry[] | ParseFailure {
  let document: unknown
  try {
    document = JSON.parse(text)
  } catch {
    return 'invalidJson'
  }
  if (typeof document !== 'object' || document === null) return 'wrongFormat'
  const { format, version, structures } = document as Record<string, unknown>
  if (format !== STRUCTURE_LIST_FORMAT || !Array.isArray(structures)) return 'wrongFormat'
  if (version !== STRUCTURE_LIST_VERSION) return 'unsupportedVersion'
  return structures as StructureListEntry[]
}

function isSelectableByDefault(line: StructureImportLine): boolean {
  return line.status === 'create' || line.status === 'update'
}

async function exportList() {
  exportError.value = false
  copied.value = false
  copyFailed.value = false
  busy.value = true
  try {
    exportText.value = JSON.stringify(await store.exportStructures(), null, JSON_INDENT)
  } catch (e) {
    console.error('Failed to export structures:', e)
    exportError.value = true
  } finally {
    busy.value = false
  }
}

async function copyExport() {
  copied.value = false
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(exportText.value)
    copied.value = true
  } catch (e) {
    console.error('Failed to copy the structure list:', e)
    copyFailed.value = true
  }
}

function downloadExport() {
  const url = URL.createObjectURL(new Blob([exportText.value], { type: 'application/json' }))
  const link = document.createElement('a')
  link.href = url
  link.download = EXPORT_FILE_NAME
  link.click()
  URL.revokeObjectURL(url)
}

async function onFileChosen(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (file === undefined) return
  importText.value = await file.text()
  resetPreview()
}

function resetPreview() {
  parsedEntries.value = []
  previewLines.value = []
  selectedIndexes.value = new Set()
  resultCounts.value = null
  importError.value = null
}

async function previewImport() {
  resetPreview()
  const parsed = parseStructureList(importText.value)
  if (typeof parsed === 'string') {
    importError.value = t(`industry.structures.transfer.errors.${parsed}`)
    return
  }
  busy.value = true
  try {
    const outcome = await store.importStructures({ entries: parsed, overwriteExisting: overwriteExisting.value, dryRun: true })
    parsedEntries.value = parsed
    previewLines.value = outcome.entries
    selectedIndexes.value = new Set(outcome.entries.filter(isSelectableByDefault).map(line => line.index))
  } catch (e) {
    console.error('Failed to preview the structure import:', e)
    importError.value = t('industry.structures.transfer.errors.request')
  } finally {
    busy.value = false
  }
}

function toggleSelection(index: number, checked: boolean) {
  const next = new Set(selectedIndexes.value)
  if (checked) next.add(index)
  else next.delete(index)
  selectedIndexes.value = next
}

async function applyImport() {
  const entries = [...selectedIndexes.value].sort((a, b) => a - b).map(index => parsedEntries.value[index])
  busy.value = true
  importError.value = null
  try {
    const outcome = await store.importStructures({ entries, overwriteExisting: overwriteExisting.value, dryRun: false })
    const counts: Record<StructureImportStatus, number> = { create: 0, update: 0, skip: 0, invalid: 0 }
    for (const line of outcome.entries) counts[line.status] += 1
    resultCounts.value = counts
    parsedEntries.value = []
    previewLines.value = []
    selectedIndexes.value = new Set()
    emit('imported')
  } catch (e) {
    console.error('Failed to import the structure list:', e)
    importError.value = t('industry.structures.transfer.errors.request')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <details class="bg-slate-800/40 rounded-lg border border-slate-700" data-testid="structure-list-transfer">
    <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-slate-300">
      {{ t('industry.structures.transfer.title') }}
    </summary>

    <div class="p-4 space-y-6 border-t border-slate-700">
      <p class="text-xs text-slate-400">{{ t('industry.structures.transfer.hint') }}</p>

      <section class="space-y-2">
        <h5 class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('industry.structures.transfer.exportTitle') }}</h5>
        <button
          type="button"
          data-testid="structure-export-button"
          :disabled="busy"
          class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 rounded-lg text-slate-100 text-sm"
          @click="exportList"
        >
          {{ t('industry.structures.transfer.exportButton') }}
        </button>
        <p v-if="exportError" class="text-xs text-red-400" data-testid="structure-export-error">{{ t('industry.structures.transfer.errors.request') }}</p>
        <template v-if="exportText !== ''">
          <textarea
            :value="exportText"
            readonly
            rows="6"
            :aria-label="t('industry.structures.transfer.exportTitle')"
            data-testid="structure-export-text"
            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-xs font-mono"
          />
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm" @click="copyExport">
              {{ t('common.actions.copy') }}
            </button>
            <button type="button" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-100 text-sm" @click="downloadExport">
              {{ t('industry.structures.transfer.download') }}
            </button>
            <span v-if="copied" class="text-xs text-emerald-400">{{ t('industry.structures.transfer.copied') }}</span>
            <span v-if="copyFailed" class="text-xs text-red-400">{{ t('industry.structures.transfer.copyFailed') }}</span>
          </div>
        </template>
      </section>

      <section class="space-y-2">
        <h5 class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('industry.structures.transfer.importTitle') }}</h5>
        <textarea
          v-model="importText"
          rows="6"
          :placeholder="t('industry.structures.transfer.importPlaceholder')"
          :aria-label="t('industry.structures.transfer.importTitle')"
          data-testid="structure-import-text"
          class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-xs font-mono"
          @input="resetPreview"
        />
        <div class="flex flex-wrap items-center gap-3">
          <label class="text-xs text-slate-400">
            {{ t('industry.structures.transfer.chooseFile') }}
            <input type="file" accept="application/json,.json" class="block text-xs text-slate-300" @change="onFileChosen" />
          </label>
          <button
            type="button"
            data-testid="structure-import-preview-button"
            :disabled="busy || importText.trim() === ''"
            class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 rounded-lg text-slate-100 text-sm"
            @click="previewImport"
          >
            {{ t('industry.structures.transfer.preview') }}
          </button>
        </div>
        <p v-if="importError" class="text-xs text-red-400" role="alert" data-testid="structure-import-error">{{ importError }}</p>

        <div v-if="hasPreview" class="space-y-2">
          <ul class="divide-y divide-slate-700 border border-slate-700 rounded-lg">
            <li
              v-for="line in previewLines"
              :key="line.index"
              data-testid="structure-import-row"
              :data-status="line.status"
              class="flex items-start gap-3 px-3 py-2 text-sm"
            >
              <input
                type="checkbox"
                data-testid="structure-import-row-select"
                class="mt-1"
                :aria-label="line.name"
                :disabled="line.status === 'invalid'"
                :checked="selectedIndexes.has(line.index)"
                @change="toggleSelection(line.index, ($event.target as HTMLInputElement).checked)"
              />
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-slate-200 truncate">{{ line.name }}</span>
                  <span
                    class="px-1.5 py-0.5 rounded-sm text-xs"
                    :class="{
                      'bg-emerald-900/50 text-emerald-300': line.status === 'create',
                      'bg-cyan-900/50 text-cyan-300': line.status === 'update',
                      'bg-slate-700 text-slate-300': line.status === 'skip',
                      'bg-red-900/50 text-red-300': line.status === 'invalid',
                    }"
                  >
                    {{ t(`industry.structures.transfer.status.${line.status}`) }}
                  </span>
                </div>
                <ul v-if="line.errors.length > 0" class="mt-1 text-xs text-red-400 list-disc pl-4">
                  <li v-for="error in line.errors" :key="error">{{ error }}</li>
                </ul>
              </div>
            </li>
          </ul>
          <label class="flex items-center gap-2 text-xs text-slate-300">
            <input v-model="overwriteExisting" type="checkbox" data-testid="structure-import-overwrite" />
            {{ t('industry.structures.transfer.overwrite') }}
          </label>
          <button
            type="button"
            data-testid="structure-import-apply"
            :disabled="busy || !hasSelection"
            class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 rounded-lg text-white text-sm font-medium"
            @click="applyImport"
          >
            {{ t('industry.structures.transfer.apply', { count: selectedIndexes.size }) }}
          </button>
        </div>

        <p v-if="resultCounts" class="text-sm text-emerald-300" role="status" data-testid="structure-import-result">
          {{ t('industry.structures.transfer.result', { created: resultCounts.create, updated: resultCounts.update, skipped: resultCounts.skip, invalid: resultCounts.invalid }) }}
        </p>
      </section>
    </div>
  </details>
</template>
