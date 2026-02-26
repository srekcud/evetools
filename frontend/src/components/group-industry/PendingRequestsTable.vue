<script setup lang="ts">
import { computed } from 'vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { GroupMember } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  isAdmin: boolean
}>()

const store = useGroupDistributionStore()
const { formatDate } = useFormatters()
const { getCharacterPortraitUrl, onImageError } = useEveImages()

const pendingMembers = computed(() =>
  store.members.filter(m => m.status === 'pending'),
)

async function handleAccept(member: GroupMember): Promise<void> {
  await store.updateMember(props.projectId, member.id, { status: 'accepted' })
}

async function handleReject(member: GroupMember): Promise<void> {
  await store.kickMember(props.projectId, member.id)
}
</script>

<template>
  <div v-if="pendingMembers.length > 0 || isAdmin" class="mb-6">
    <div class="flex items-center gap-3 mb-4">
      <h3 class="text-sm font-semibold text-slate-200 uppercase tracking-wider">Pending Requests</h3>
      <span
        v-if="pendingMembers.length > 0"
        class="text-xs px-2 py-0.5 rounded bg-amber-500/15 text-amber-400 border border-amber-500/20 font-medium"
      >{{ pendingMembers.length }}</span>
    </div>

    <!-- Empty state -->
    <div
      v-if="pendingMembers.length === 0"
      class="bg-slate-900/80 rounded-xl border border-slate-800 p-8 text-center"
    >
      <p class="text-sm text-slate-500">No pending requests</p>
      <p class="text-xs text-slate-600 mt-1">Share the join link to invite new members</p>
    </div>

    <!-- Pending table -->
    <div v-else class="bg-slate-900/80 rounded-xl border border-amber-500/20 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
            <th class="text-left py-3 px-6">Character</th>
            <th class="text-left py-3 px-3">Corporation</th>
            <th class="text-left py-3 px-3">Requested</th>
            <th class="text-left py-3 px-3">Via</th>
            <th class="text-center py-3 px-6">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="member in pendingMembers"
            :key="member.id"
            class="hover:bg-slate-800/30 bg-amber-500/[0.02]"
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
            <!-- Requested date -->
            <td class="py-3 px-3 text-sm text-slate-400">{{ formatDate(member.joinedAt) }}</td>
            <!-- Via -->
            <td class="py-3 px-3">
              <span class="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-500 border border-slate-700">Short Link</span>
            </td>
            <!-- Actions -->
            <td class="py-3 px-6 text-center">
              <div v-if="isAdmin" class="flex items-center justify-center gap-1.5">
                <button
                  class="px-3 py-1.5 rounded bg-emerald-600/20 text-emerald-400 text-xs font-medium hover:bg-emerald-600/30 transition-colors border border-emerald-600/30"
                  @click="handleAccept(member)"
                >Accept</button>
                <button
                  class="px-3 py-1.5 rounded bg-red-600/20 text-red-400 text-xs font-medium hover:bg-red-600/30 transition-colors border border-red-600/30"
                  @click="handleReject(member)"
                >Reject</button>
              </div>
              <span v-else class="text-xs text-slate-600">--</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
