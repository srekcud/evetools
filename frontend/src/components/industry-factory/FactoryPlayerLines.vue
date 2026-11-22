<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useSlotsStore } from '@/stores/industry/slots'
import type { SlotActivity } from '@/stores/industry/types'

const ACTIVITIES: SlotActivity[] = ['manufacturing', 'reaction', 'science']

const { t } = useI18n()
const store = useSlotsStore()

const characters = computed(() => store.data?.characters ?? [])

onMounted(() => store.fetchSlots())
</script>

<template>
  <section data-testid="factory-player-lines" class="mb-8">
    <div class="flex items-baseline gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('industryFactory.playerLines.title') }}</h2>
      <span class="text-sm text-slate-400">{{ t('industryFactory.playerLines.subtitle') }}</span>
    </div>

    <p v-if="store.data === null && store.error" class="text-sm text-red-400">{{ store.error }}</p>
    <p v-else-if="store.data === null" class="text-sm text-slate-400">{{ t('common.actions.loading') }}</p>
    <p v-else-if="characters.length === 0" class="text-sm text-slate-400">{{ t('industryFactory.playerLines.empty') }}</p>

    <div v-else class="overflow-x-auto bg-slate-900 rounded-xl border border-slate-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-3 py-2">{{ t('industryFactory.playerLines.columns.player') }}</th>
            <th v-for="activity in ACTIVITIES" :key="activity" class="px-3 py-2 text-right">{{ t(`industry.slots.kpi.${activity}`) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="character in characters" :key="character.characterId" data-testid="factory-player-row" class="border-t border-slate-800">
            <td class="px-3 py-2 text-slate-200">{{ character.characterName }}</td>
            <td v-for="activity in ACTIVITIES" :key="activity" class="px-3 py-2 text-right text-slate-200">
              {{ character.slots[activity].used }} /
              <template v-if="character.slots[activity].max !== null">{{ character.slots[activity].max }}</template>
              <UnknownValue v-else data-unknown="true" />
              <span v-if="character.slots[activity].max !== null" class="ml-2 text-xs text-emerald-400">{{ t('industryFactory.playerLines.free', { count: character.slots[activity].max - character.slots[activity].used }) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
