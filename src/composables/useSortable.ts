import { onMounted, onBeforeUnmount, ref, type Ref } from 'vue'
import Sortable from 'sortablejs'

interface SortableOptions {
	onEnd?: (oldIndex: number, newIndex: number) => void
	group?: string | Sortable.GroupOptions
	handle?: string
	animation?: number
}

export function useSortable(
	elementRef: Ref<HTMLElement | null>,
	options: SortableOptions = {},
) {
	const instance = ref<Sortable | null>(null)

	onMounted(() => {
		if (!elementRef.value) return
		instance.value = Sortable.create(elementRef.value, {
			animation: options.animation ?? 150,
			group: options.group,
			handle: options.handle,
			onEnd(evt) {
				if (evt.oldIndex !== undefined && evt.newIndex !== undefined) {
					options.onEnd?.(evt.oldIndex, evt.newIndex)
				}
			},
		})
	})

	onBeforeUnmount(() => {
		instance.value?.destroy()
		instance.value = null
	})

	return { instance }
}
