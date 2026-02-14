<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Props {
  total: number
  newBm: string
  enVente: { count: number; total: number }
  vendu: { count: number; total: number }
}

defineProps<Props>()
</script>

<template>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <!-- Total -->
    <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-cyan-500/40 hover:shadow-lg hover:shadow-cyan-500/10 hover:-translate-y-1">
      <div class="absolute inset-0 bg-gradient-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
      <div class="relative">
        <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('escalations.kpi.total') }}</p>
        <p class="text-3xl font-bold text-cyan-400">{{ total }}</p>
      </div>
    </div>

    <!-- Sans BM / BM -->
    <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-cyan-500/40 hover:shadow-lg hover:shadow-cyan-500/10 hover:-translate-y-1">
      <div class="absolute inset-0 bg-gradient-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
      <div class="relative">
        <p class="text-slate-500 text-sm uppercase tracking-wider mb-1"><span class="line-through">BM</span> / {{ t('escalations.kpi.noBmSlashBm') }}</p>
        <p class="text-3xl font-bold text-cyan-400">{{ newBm }}</p>
      </div>
    </div>

    <!-- En vente -->
    <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-amber-500/40 hover:shadow-lg hover:shadow-amber-500/10 hover:-translate-y-1">
      <div class="absolute inset-0 bg-gradient-to-r from-transparent via-amber-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
      <div class="relative">
        <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('escalations.kpi.forSale') }}</p>
        <p class="text-3xl font-bold text-amber-400">{{ enVente.count }}</p>
        <p v-if="enVente.total > 0" class="text-xs text-slate-500 mt-1">{{ enVente.total }}m ISK</p>
      </div>
    </div>

    <!-- Vendues -->
    <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/10 hover:-translate-y-1">
      <div class="absolute inset-0 bg-gradient-to-r from-transparent via-emerald-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
      <div class="relative">
        <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('escalations.kpi.sold') }}</p>
        <p class="text-3xl font-bold text-emerald-400">{{ vendu.count }}</p>
        <p v-if="vendu.total > 0" class="text-xs text-slate-500 mt-1">{{ vendu.total }}m ISK</p>
      </div>
    </div>
  </div>
</template>
