<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningOperationMember } from '@/stores/miningOperation'

const props = defineProps<{
  members: MiningOperationMember[]
  ownCharacterIds: string[]
  canAdminister: boolean
  isOwner: boolean
}>()

const emit = defineEmits<{
  accept: [member: MiningOperationMember]
  remove: [member: MiningOperationMember]
  promote: [member: MiningOperationMember]
  demote: [member: MiningOperationMember]
}>()

const { t } = useI18n()

const ownCharacterIdSet = computed(() => new Set(props.ownCharacterIds))

function isYou(member: MiningOperationMember): boolean {
  return member.characters.some(character => ownCharacterIdSet.value.has(character.id))
}

function memberName(member: MiningOperationMember): string {
  return member.characterName ?? t('miningOperation.unknownPilot')
}

function canManage(member: MiningOperationMember): boolean {
  if (!props.canAdminister || member.role === 'owner') return false
  return props.isOwner || member.role !== 'admin'
}

const ROLE_BADGE_CLASS: Record<string, string> = {
  owner: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  admin: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
}
</script>

<template>
  <ul class="divide-y divide-slate-800">
    <li v-for="member in members" :key="member.id" class="py-3">
      <div class="flex flex-wrap items-center gap-2">
        <span class="min-w-0 max-w-full truncate font-display text-base font-semibold text-slate-100" :title="memberName(member)">{{ memberName(member) }}</span>
        <span
          v-if="member.role !== 'member'"
          class="shrink-0 rounded-sm border px-1.5 py-0.5 font-mono text-xs uppercase tracking-wider"
          :class="ROLE_BADGE_CLASS[member.role]"
        >{{ t(`miningOperation.role.${member.role}`) }}</span>
        <span v-if="member.status === 'pending'" class="shrink-0 rounded-sm border border-amber-500/40 bg-amber-500/10 px-1.5 py-0.5 font-mono text-xs text-amber-300">{{ t('miningOperation.detail.pending') }}</span>
        <span v-if="isYou(member)" class="shrink-0 font-mono text-xs text-slate-400">({{ t('miningOperation.detail.you') }})</span>

        <div v-if="canManage(member)" class="ml-auto flex flex-wrap items-center gap-2">
          <template v-if="member.status === 'pending'">
            <button type="button" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-500" @click="emit('accept', member)">{{ t('miningOperation.detail.accept') }}</button>
            <button type="button" class="rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800" @click="emit('remove', member)">{{ t('miningOperation.detail.refuse') }}</button>
          </template>
          <template v-else>
            <button v-if="isOwner && member.role === 'member'" type="button" class="rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800" @click="emit('promote', member)">{{ t('miningOperation.detail.promote') }}</button>
            <button v-if="isOwner && member.role === 'admin'" type="button" class="rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800" @click="emit('demote', member)">{{ t('miningOperation.detail.demote') }}</button>
            <button type="button" class="rounded-md border border-red-500/40 px-3 py-1 text-xs text-red-300 hover:bg-red-500/10" @click="emit('remove', member)">{{ t('miningOperation.detail.remove') }}</button>
          </template>
        </div>
      </div>
      <ul class="mt-2 flex flex-wrap gap-1.5">
        <li
          v-for="character in member.characters"
          :key="character.id"
          class="max-w-full truncate rounded-sm border border-slate-700 bg-slate-900 px-2 py-0.5 font-mono text-xs text-slate-300"
          :title="character.name"
        >{{ character.name }}</li>
      </ul>
    </li>
  </ul>
</template>
