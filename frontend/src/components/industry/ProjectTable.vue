<script setup lang="ts">
import { ref } from 'vue'
import { useIndustryStore, type IndustryProject } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { parseIskValue } from '@/composables/useIskParser'

const emit = defineEmits<{
  'view-project': [id: string]
}>()

const store = useIndustryStore()
const { formatIsk } = useFormatters()

// Inline editing state
const editingCell = ref<{ id: string; field: string } | null>(null)
const editValue = ref<string>('')

function startEdit(project: IndustryProject, field: string) {
  // Don't allow editing completed projects
  if (project.status === 'completed') return
  const value = (project as unknown as Record<string, unknown>)[field]
  editValue.value = value !== null && value !== undefined ? String(value) : ''
  editingCell.value = { id: project.id, field }
}

async function saveEdit(project: IndustryProject) {
  if (!editingCell.value) return
  const field = editingCell.value.field

  // For runs, parse as integer; for costs, use ISK parser
  let numValue: number | null
  if (field === 'runs') {
    const parsed = parseInt(editValue.value, 10)
    numValue = isNaN(parsed) || parsed < 1 ? project.runs : parsed
  } else {
    numValue = parseIskValue(editValue.value)
  }

  editingCell.value = null

  await store.updateProject(project.id, { [field]: numValue } as Partial<IndustryProject>)
  await store.fetchProjects()
}

function cancelEdit() {
  editingCell.value = null
}

function isEditing(id: string, field: string): boolean {
  return editingCell.value?.id === id && editingCell.value?.field === field
}

function formatValue(value: number | null | undefined): string {
  if (value === null || value === undefined) return '-'
  return formatIsk(value)
}

function profitClass(profit: number | null | undefined): string {
  if (profit === null || profit === undefined) return 'text-slate-400'
  return profit >= 0 ? 'text-emerald-400' : 'text-red-400'
}

async function toggleStatus(project: IndustryProject) {
  const newStatus = project.status === 'active' ? 'completed' : 'active'
  await store.updateProject(project.id, { status: newStatus })
  await store.fetchProjects()
}

async function deleteProject(id: string) {
  await store.deleteProject(id)
}

async function togglePersonalUse(project: IndustryProject) {
  // Don't allow toggling for completed projects
  if (project.status === 'completed') return
  await store.updateProject(project.id, { personalUse: !project.personalUse })
  await store.fetchProjects()
}

// Split editable fields to insert jobsCost (readonly) in the middle
const costFields = ['bpoCost', 'materialCost', 'transportCost']
const afterJobsFields = ['taxAmount', 'sellPrice']
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
          <th class="text-left py-3 px-3">Produit</th>
          <th class="text-left py-3 px-2">Runs</th>
          <th class="text-left py-3 px-2">ME</th>
          <th class="text-center py-3 px-2">Perso</th>
          <th class="text-right py-3 px-2">BPC Kit</th>
          <th class="text-right py-3 px-2">Matériaux</th>
          <th class="text-right py-3 px-2">Transport</th>
          <th class="text-right py-3 px-2">Jobs</th>
          <th class="text-right py-3 px-2">Taxes</th>
          <th class="text-right py-3 px-2">Vente</th>
          <th class="text-right py-3 px-2">Profit</th>
          <th class="text-right py-3 px-2">%</th>
          <th class="text-center py-3 px-2">Status</th>
          <th class="text-center py-3 px-2"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr
          v-for="project in store.projects"
          :key="project.id"
          class="hover:bg-slate-800/50 group"
        >
          <!-- Product name -->
          <td class="py-3 px-3">
            <button
              @click="emit('view-project', project.id)"
              class="text-cyan-400 hover:text-cyan-300 font-medium"
            >
              {{ project.productTypeName }}
            </button>
          </td>

          <!-- Runs (editable if not completed) -->
          <td class="py-3 px-2">
            <input
              v-if="isEditing(project.id, 'runs')"
              v-model="editValue"
              type="number"
              min="1"
              class="w-16 bg-slate-700 border border-cyan-500 rounded px-2 py-1 text-sm focus:outline-none"
              @keydown.enter="saveEdit(project)"
              @keydown.escape="cancelEdit"
              @blur="saveEdit(project)"
              autofocus
            />
            <span
              v-else
              @dblclick="startEdit(project, 'runs')"
              :class="[
                'text-slate-300',
                project.status !== 'completed' ? 'cursor-pointer hover:text-cyan-400' : ''
              ]"
              :title="project.status !== 'completed' ? 'Double-cliquer pour modifier' : 'Projet terminé'"
            >
              {{ project.runs }}
            </span>
          </td>

          <!-- ME -->
          <td class="py-3 px-2 text-slate-300">{{ project.meLevel }}</td>

          <!-- Personal Use toggle -->
          <td class="py-3 px-2 text-center">
            <button
              @click="togglePersonalUse(project)"
              :disabled="project.status === 'completed'"
              :class="[
                'text-xs px-2 py-1 rounded',
                project.personalUse
                  ? 'bg-amber-500/20 text-amber-400'
                  : 'bg-slate-600/20 text-slate-500',
                project.status === 'completed' ? 'opacity-50 cursor-not-allowed' : ''
              ]"
              :title="project.status === 'completed' ? 'Projet terminé' : (project.personalUse ? 'Usage personnel' : 'À vendre')"
            >
              {{ project.personalUse ? 'Perso' : 'Vente' }}
            </button>
          </td>

          <!-- Editable cost fields (BPC, Matériaux, Transport) -->
          <td
            v-for="field in costFields"
            :key="field"
            class="py-3 px-2 text-right"
          >
            <!-- Editing mode -->
            <input
              v-if="isEditing(project.id, field)"
              v-model="editValue"
              type="text"
              placeholder="ex: 10M, 1.5B"
              class="w-24 bg-slate-700 border border-cyan-500 rounded px-2 py-1 text-right text-sm focus:outline-none"
              @keydown.enter="saveEdit(project)"
              @keydown.escape="cancelEdit"
              @blur="saveEdit(project)"
              autofocus
            />
            <!-- Display mode -->
            <span
              v-else
              @dblclick="startEdit(project, field)"
              :class="[
                'font-mono text-slate-300',
                project.status !== 'completed' ? 'cursor-pointer hover:text-cyan-400' : ''
              ]"
              :title="project.status !== 'completed' ? 'Double-cliquer pour modifier' : 'Projet terminé'"
            >
              {{ formatValue((project as Record<string, unknown>)[field] as number | null) }}
            </span>
          </td>

          <!-- Jobs cost (readonly) -->
          <td class="py-3 px-2 text-right font-mono text-slate-400">
            {{ formatValue(project.jobsCost) }}
          </td>

          <!-- Editable fields after Jobs (Taxes, Vente) - hidden for personal use -->
          <template v-if="!project.personalUse">
            <td
              v-for="field in afterJobsFields"
              :key="field"
              class="py-3 px-2 text-right"
            >
              <!-- Editing mode -->
              <input
                v-if="isEditing(project.id, field)"
                v-model="editValue"
                type="text"
                placeholder="ex: 10M, 1.5B"
                class="w-24 bg-slate-700 border border-cyan-500 rounded px-2 py-1 text-right text-sm focus:outline-none"
                @keydown.enter="saveEdit(project)"
                @keydown.escape="cancelEdit"
                @blur="saveEdit(project)"
                autofocus
              />
              <!-- Display mode -->
              <span
                v-else
                @dblclick="startEdit(project, field)"
                :class="[
                  'font-mono text-slate-300',
                  project.status !== 'completed' ? 'cursor-pointer hover:text-cyan-400' : ''
                ]"
                :title="project.status !== 'completed' ? 'Double-cliquer pour modifier' : 'Projet terminé'"
              >
                {{ formatValue((project as Record<string, unknown>)[field] as number | null) }}
              </span>
            </td>

            <!-- Profit -->
            <td class="py-3 px-2 text-right font-mono" :class="profitClass(project.profit)">
              {{ formatValue(project.profit) }}
            </td>

            <!-- Profit % -->
            <td class="py-3 px-2 text-right font-mono" :class="profitClass(project.profitPercent)">
              {{ project.profitPercent !== null ? `${project.profitPercent}%` : '-' }}
            </td>
          </template>

          <!-- Placeholder cells for personal use projects -->
          <template v-else>
            <td class="py-3 px-2 text-right text-slate-600 font-mono">N/A</td>
            <td class="py-3 px-2 text-right text-slate-600 font-mono">N/A</td>
            <td class="py-3 px-2 text-right text-slate-600 font-mono">N/A</td>
            <td class="py-3 px-2 text-right text-slate-600 font-mono">N/A</td>
          </template>

          <!-- Status -->
          <td class="py-3 px-2 text-center">
            <button
              @click="toggleStatus(project)"
              :class="[
                'text-xs px-2 py-1 rounded',
                project.status === 'completed'
                  ? 'bg-emerald-500/20 text-emerald-400'
                  : 'bg-cyan-500/20 text-cyan-400',
              ]"
            >
              {{ project.status === 'completed' ? 'Terminé' : 'Actif' }}
            </button>
          </td>

          <!-- Actions -->
          <td class="py-3 px-2 text-center">
            <div class="flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
              <button
                @click="emit('view-project', project.id)"
                class="p-1 hover:bg-slate-700 rounded text-slate-400 hover:text-cyan-400"
                title="Détails"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
              <button
                @click="deleteProject(project.id)"
                class="p-1 hover:bg-red-500/20 rounded text-slate-400 hover:text-red-400"
                title="Supprimer"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </td>
        </tr>
      </tbody>
      <!-- Total row -->
      <tfoot v-if="store.projects.length > 0">
        <tr class="border-t border-slate-600 font-semibold text-slate-200">
          <td colspan="10" class="py-3 px-3 text-right">Total Profit</td>
          <td class="py-3 px-2 text-right font-mono" :class="profitClass(store.totalProfit)">
            {{ formatValue(store.totalProfit) }}
          </td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>

    <div v-if="store.projects.length === 0" class="text-center py-12 text-slate-500">
      Aucun projet. Créez votre premier projet de construction ci-dessus.
    </div>
  </div>
</template>
