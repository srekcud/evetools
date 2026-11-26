import { ref } from 'vue'
import { useCopyFeedback } from '@/composables/useCopyFeedback'
import { downloadBlob } from '@/utils/downloadBlob'

const FILENAME_FORBIDDEN_CHARACTERS = /[\\/:*?"<>|]/g

export function exportedColonyTemplate(name: string, gameTemplate: Record<string, unknown>): string {
  return JSON.stringify({ ...gameTemplate, Cmt: name })
}

export function colonyTemplateFileName(name: string): string {
  return `${name.replace(FILENAME_FORBIDDEN_CHARACTERS, '_')}.json`
}

export function useColonyLayoutExport() {
  const { copied, failed, copy } = useCopyFeedback()
  const lastCopiedLayoutId = ref<string | null>(null)

  async function copyTemplate(layoutId: string, name: string, gameTemplate: Record<string, unknown>): Promise<void> {
    lastCopiedLayoutId.value = layoutId
    await copy(exportedColonyTemplate(name, gameTemplate))
  }

  function downloadTemplate(name: string, gameTemplate: Record<string, unknown>): void {
    downloadBlob(new Blob([exportedColonyTemplate(name, gameTemplate)], { type: 'application/json' }), colonyTemplateFileName(name))
  }

  return { copied, failed, lastCopiedLayoutId, copyTemplate, downloadTemplate }
}
