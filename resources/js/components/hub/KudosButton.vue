<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ThumbsUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import { give, take } from '@/routes/jobs/kudos';

// Kudos on a job (#4646): a toggle for signed-in drivers on other drivers' jobs, a plain
// count for everyone else (hidden while it is 0).
const props = defineProps<{
    jobId: number;
    driverId: number;
    count: number;
    given: boolean;
}>();

const viewerId = computed(() => usePage().props.auth.user?.id ?? null);
const canGive = computed(
    () => viewerId.value !== null && viewerId.value !== props.driverId,
);
const busy = ref(false);

const label = computed(() => {
    const n = `${props.count} kudos`;

    if (!canGive.value) {
        return n;
    }

    return props.given ? `Take back kudos (${n})` : `Give kudos (${n})`;
});

function toggle() {
    if (busy.value) {
        return;
    }

    busy.value = true;
    const route = props.given ? take(props.jobId) : give(props.jobId);
    router.visit(route.url, {
        method: route.method,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <button
        v-if="canGive"
        type="button"
        :aria-pressed="given"
        :aria-label="label"
        :title="given ? 'Take back kudos' : 'Give kudos'"
        :disabled="busy"
        class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-sm tabular-nums transition-colors hover:bg-accent disabled:opacity-60"
        :class="given ? 'text-amber-500' : 'text-muted-foreground'"
        data-test="kudos"
        @click="toggle"
    >
        <ThumbsUp class="size-4" :fill="given ? 'currentColor' : 'none'" />
        <span>{{ count }}</span>
    </button>
    <span
        v-else-if="count > 0"
        :aria-label="label"
        :title="label"
        class="inline-flex items-center gap-1 px-1.5 py-1 text-sm text-muted-foreground tabular-nums"
        data-test="kudos-count"
    >
        <ThumbsUp class="size-4" />
        <span>{{ count }}</span>
    </span>
</template>
