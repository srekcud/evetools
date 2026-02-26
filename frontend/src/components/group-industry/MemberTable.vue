<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { GroupMember, GroupMemberRole } from '@/stores/group-industry/types'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const props = defineProps<{
  projectId: string
  isAdmin: boolean
}>()

const store = useGroupDistributionStore()
const { formatDate } = useFormatters()
const { getCharacterPortraitUrl, onImageError } = useEveImages()

// Accepted members only
const acceptedMembers = computed(() =>
  store.members.filter(m => m.status === 'accepted'),
)

// Confirm modal state
const confirmModal = ref({
  show: false,
  title: '',
  subtitle: '',
  confirmLabel: '',
  confirmColor: 'red' as 'red' | 'emerald' | 'cyan',
  icon: 'delete' as 'delete' | 'check',
  action: () => Promise.resolve(),
})

const ROLE_BADGE_CLASSES: Record<GroupMemberRole, string> = {
  owner: 'bg-amber-500/15 text-amber-400 border-amber-500/25',
  admin: 'bg-cyan-500/15 text-cyan-400 border-cyan-500/25',
  member: 'bg-slate-700 text-slate-400 border-slate-600',
}

const ROLE_LABELS: Record<GroupMemberRole, string> = {
  owner: 'Owner',
  admin: 'Admin',
  member: 'Member',
}

function canManage(member: GroupMember): boolean {
  return props.isAdmin && member.role !== 'owner'
}

function confirmPromote(member: GroupMember): void {
  confirmModal.value = {
    show: true,
    title: 'Promote to Admin',
    subtitle: `Promote ${member.characterName} to admin?`,
    confirmLabel: 'Promote',
    confirmColor: 'cyan',
    icon: 'check',
    action: () => store.updateMember(props.projectId, member.id, { role: 'admin' }),
  }
}

function confirmDemote(member: GroupMember): void {
  confirmModal.value = {
    show: true,
    title: 'Demote to Member',
    subtitle: `Demote ${member.characterName} to member?`,
    confirmLabel: 'Demote',
    confirmColor: 'red',
    icon: 'delete',
    action: () => store.updateMember(props.projectId, member.id, { role: 'member' }),
  }
}

function confirmKick(member: GroupMember): void {
  confirmModal.value = {
    show: true,
    title: 'Kick Member',
    subtitle: `Remove ${member.characterName} from the project?`,
    confirmLabel: 'Kick',
    confirmColor: 'red',
    icon: 'delete',
    action: () => store.kickMember(props.projectId, member.id),
  }
}

async function handleConfirm(): Promise<void> {
  await confirmModal.value.action()
  confirmModal.value.show = false
}

onMounted(() => {
  store.fetchMembers(props.projectId)
})
</script>

<template>
  <!-- Loading -->
  <div v-if="store.loading" class="flex items-center justify-center py-12">
    <LoadingSpinner size="lg" class="text-cyan-400" />
  </div>

  <!-- Members Table -->
  <div v-else class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden mb-6">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
            <th class="text-left py-3 px-6">Character</th>
            <th class="text-left py-3 px-3">Corporation</th>
            <th class="text-left py-3 px-3">Role</th>
            <th class="text-center py-3 px-3">Status</th>
            <th class="text-left py-3 px-3">Joined</th>
            <th class="text-center py-3 px-6">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/30">
          <tr
            v-for="member in acceptedMembers"
            :key="member.id"
            class="hover:bg-slate-800/30"
          >
            <!-- Character -->
            <td class="py-3 px-6">
              <div class="flex items-center gap-2.5">
                <img
                  :src="getCharacterPortraitUrl(member.characterId, 32)"
                  :alt="member.characterName"
                  class="w-8 h-8 rounded-full border border-slate-700"
                  @error="onImageError"
                />
                <span class="text-slate-200 font-medium">{{ member.characterName }}</span>
              </div>
            </td>
            <!-- Corporation -->
            <td class="py-3 px-3">
              <span class="text-sm text-slate-400">{{ member.corporationId ? `Corp #${member.corporationId}` : '--' }}</span>
            </td>
            <!-- Role -->
            <td class="py-3 px-3">
              <span
                class="text-xs px-2 py-0.5 rounded border font-medium"
                :class="ROLE_BADGE_CLASSES[member.role]"
              >{{ ROLE_LABELS[member.role] }}</span>
            </td>
            <!-- Status -->
            <td class="py-3 px-3 text-center">
              <div class="flex items-center justify-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                <span class="text-xs text-emerald-400">Active</span>
              </div>
            </td>
            <!-- Joined -->
            <td class="py-3 px-3 text-sm text-slate-400">{{ formatDate(member.joinedAt) }}</td>
            <!-- Actions -->
            <td class="py-3 px-6 text-center">
              <div v-if="canManage(member)" class="flex items-center justify-center gap-1.5">
                <!-- Promote (only for members) -->
                <button
                  v-if="member.role === 'member'"
                  class="px-2 py-1 rounded bg-cyan-600/20 text-cyan-400 text-xs font-medium hover:bg-cyan-600/30 border border-cyan-600/30"
                  @click="confirmPromote(member)"
                >Promote</button>
                <!-- Demote (only for admins) -->
                <button
                  v-if="member.role === 'admin'"
                  class="px-2 py-1 rounded bg-slate-600/20 text-slate-400 text-xs font-medium hover:bg-slate-600/30 border border-slate-600/30"
                  @click="confirmDemote(member)"
                >Demote</button>
                <!-- Kick -->
                <button
                  class="px-2.5 py-1 rounded bg-red-600/10 text-red-400/70 text-xs font-medium hover:bg-red-600/20 hover:text-red-400 transition-colors border border-red-600/20"
                  @click="confirmKick(member)"
                >Kick</button>
              </div>
              <span v-else class="text-xs text-slate-600">--</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Error -->
  <div v-if="store.error" class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
    <p class="text-sm text-red-400">{{ store.error }}</p>
  </div>

  <!-- Confirm modal -->
  <ConfirmModal
    :show="confirmModal.show"
    :title="confirmModal.title"
    :subtitle="confirmModal.subtitle"
    :confirm-label="confirmModal.confirmLabel"
    :confirm-color="confirmModal.confirmColor"
    :icon="confirmModal.icon"
    @confirm="handleConfirm"
    @cancel="confirmModal.show = false"
  />
</template>
