<template>
	<nav v-if="total > perPage" class="pagination">
		<button type="button"
			class="pagination__btn"
			:disabled="page <= 1"
			@click="$emit('update:page', page - 1)">
			‹ {{ t('sendentsynchroniser', 'Prev') }}
		</button>
		<span class="pagination__label">
			{{ t('sendentsynchroniser', 'Page {page} of {pages}', { page, pages }) }}
		</span>
		<button type="button"
			class="pagination__btn"
			:disabled="page >= pages"
			@click="$emit('update:page', page + 1)">
			{{ t('sendentsynchroniser', 'Next') }} ›
		</button>
		<select class="pagination__select"
			:value="perPage"
			@change="onPerPageChange">
			<option v-for="n in pageSizes" :key="n" :value="n">
				{{ t('sendentsynchroniser', '{n} / page', { n }) }}
			</option>
		</select>
	</nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'

const props = withDefaults(defineProps<{
	page: number
	perPage: number
	total: number
	pageSizes?: number[]
}>(), {
	pageSizes: () => [10, 30, 50, 100],
})

const emit = defineEmits<{
	(e: 'update:page', value: number): void
	(e: 'update:perPage', value: number): void
}>()

const pages = computed<number>(() => Math.max(1, Math.ceil(props.total / props.perPage)))

/**
 *
 * @param ev
 */
function onPerPageChange(ev: Event): void {
	const v = Number((ev.target as HTMLSelectElement).value)
	emit('update:perPage', v)
}
</script>

<style scoped>
.pagination { display: flex; align-items: center; gap: 8px; margin-top: 12px; }
.pagination__btn { padding: 4px 10px; }
.pagination__btn:disabled { opacity: 0.4; cursor: not-allowed; }
.pagination__label { color: var(--color-text-maxcontrast, #555); font-size: 13px; }
.pagination__select { margin-left: auto; padding: 4px 6px; }
</style>
