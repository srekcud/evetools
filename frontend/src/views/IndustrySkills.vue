<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import SellMarketUnknownValue from '@/components/industry/SellMarketUnknownValue.vue'
import SkillGainsTable from '@/components/industry/SkillGainsTable.vue'
import { useFormatters } from '@/composables/useFormatters'
import MainLayout from '@/layouts/MainLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { useSkillGainsStore } from '@/stores/industry/skillGains'

const { t } = useI18n()
const router = useRouter()
const { formatIsk } = useFormatters()
const authStore = useAuthStore()
const skillGainsStore = useSkillGainsStore()

const characters = computed(() => authStore.user?.characters ?? [])
const selectedCharacterId = ref<string | null>(null)
const selectedCharacter = computed(() => characters.value.find(character => character.id === selectedCharacterId.value) ?? null)

onMounted(() => {
  const main = characters.value.find(character => character.isMain)
  selectedCharacterId.value = main?.id ?? characters.value[0]?.id ?? null
})

watch(selectedCharacterId, characterId => {
  if (characterId !== null) {
    skillGainsStore.fetchSkillGains(characterId)
  }
}, { immediate: true })

function backToWorkshop() {
  router.push({ name: 'industry' })
}
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p v-if="selectedCharacter" class="text-xs font-mono uppercase tracking-[0.14em] text-cyan-400">
            {{ t('industry.skillGains.kicker', { name: selectedCharacter.name }) }}
          </p>
          <h1 class="font-display text-2xl font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.skillGains.title') }}</h1>
          <p class="text-sm text-slate-400">{{ t('industry.skillGains.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-3">
          <select
            v-if="characters.length > 0"
            v-model="selectedCharacterId"
            data-testid="skills-character-select"
            :aria-label="t('industry.skillGains.character')"
            class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200"
          >
            <option v-for="character in characters" :key="character.id" :value="character.id">{{ character.name }}</option>
          </select>
          <button
            data-testid="skills-back"
            class="px-3.5 py-1.5 rounded-lg text-sm font-medium text-slate-300 border border-slate-700 hover:border-cyan-500/50"
            @click="backToWorkshop"
          >{{ t('industry.skillGains.back') }}</button>
        </div>
      </div>

      <p
        v-if="characters.length === 0"
        data-testid="skills-empty"
        class="bg-slate-900 rounded-xl p-8 border border-slate-800 text-center text-slate-400"
      >{{ t('industry.skillGains.noCharacter') }}</p>

      <ErrorBanner v-else-if="skillGainsStore.error !== null" :message="skillGainsStore.error" />

      <div v-else-if="skillGainsStore.skillGains === null" class="flex justify-center py-12">
        <LoadingSpinner size="lg" class="text-cyan-500" />
      </div>

      <section v-else class="bg-slate-900 rounded-xl border border-slate-800">
        <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
          <h2 class="font-display text-base font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.skillGains.cardTitle') }}</h2>
          <span data-testid="skills-isk-per-hour" class="text-xs font-mono px-2 py-0.5 rounded border border-cyan-500/30 bg-cyan-500/10 text-cyan-400">
            <SellMarketUnknownValue v-if="skillGainsStore.skillGains.iskPerHour === null" :cause="t('industry.skillGains.causes.isk_per_hour_unknown')" />
            <template v-else>{{ t('industry.skillGains.valuedAt', { isk: formatIsk(skillGainsStore.skillGains.iskPerHour) }) }}</template>
          </span>
        </header>

        <p
          v-if="skillGainsStore.skillGains.lines.length === 0"
          data-testid="skills-empty"
          class="px-4 py-8 text-center text-sm text-slate-500"
        >{{ t('industry.skillGains.empty') }}</p>
        <SkillGainsTable v-else :lines="skillGainsStore.skillGains.lines" />

        <p class="px-4 py-3 text-xs text-slate-500">{{ t('industry.skillGains.hint') }}</p>
      </section>
    </div>
  </MainLayout>
</template>
