<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { Check, Target, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Panel from '@/components/hub/Panel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCount, formatKm, formatMoney } from '@/lib/format';
import { update as updateGoals } from '@/routes/companies/goals';
import { show as showDriver } from '@/routes/drivers';
import type { CompanyGoals, GoalMetric } from '@/types/hub';

/** Company goals (#4649): this month's progress bars, who contributed, past months. */
const props = defineProps<{
    goals: CompanyGoals;
    slug: string;
    canManage: boolean;
}>();

const editing = ref(false);

const metrics: { key: GoalMetric; label: string; unit: string }[] = [
    { key: 'distance_km', label: 'Distance', unit: 'km' },
    { key: 'deliveries', label: 'Deliveries', unit: 'jobs' },
    { key: 'income', label: 'Income', unit: '€' },
];

function label(metric: GoalMetric): string {
    return metrics.find((m) => m.key === metric)?.label ?? metric;
}

function format(metric: GoalMetric, value: number): string {
    if (metric === 'distance_km') {
        return formatKm(value);
    }

    return metric === 'income' ? formatMoney(value) : formatCount(value);
}

function targetOf(metric: GoalMetric): number | '' {
    return props.goals.current.find((g) => g.metric === metric)?.target ?? '';
}

/** "November 2026" from "2026-11", in UTC (goals use UTC months). */
function monthName(key: string): string {
    const [year, month] = key.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

const visible = computed(
    () =>
        props.canManage ||
        props.goals.current.length > 0 ||
        props.goals.history.length > 0,
);
</script>

<template>
    <Panel
        v-if="visible"
        title="Monthly goals"
        :description="monthName(goals.month)"
        data-test="company-goals"
    >
        <template v-if="canManage && !editing" #actions>
            <Button
                variant="outline"
                size="sm"
                data-test="edit-goals"
                @click="editing = true"
                ><Target class="size-4" />
                {{ goals.current.length ? 'Edit goals' : 'Set goals' }}</Button
            >
        </template>

        <Form
            v-if="editing"
            v-bind="updateGoals.form(slug)"
            class="mb-4 space-y-3"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            @success="editing = false"
        >
            <p class="text-sm text-muted-foreground">
                Targets for {{ monthName(goals.month) }} (UTC). Leave a box
                empty for no goal.
            </p>
            <div class="grid gap-3 sm:grid-cols-3">
                <div v-for="m in metrics" :key="m.key" class="grid gap-1.5">
                    <Label :for="`goal-${m.key}`"
                        >{{ m.label }} ({{ m.unit }})</Label
                    >
                    <Input
                        :id="`goal-${m.key}`"
                        :name="m.key"
                        type="number"
                        min="1"
                        step="1"
                        :default-value="targetOf(m.key)"
                        placeholder="No goal"
                    />
                    <InputError :message="errors[m.key]" />
                </div>
            </div>
            <div class="flex gap-2">
                <Button size="sm" :disabled="processing" data-test="save-goals"
                    >Save goals</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="editing = false"
                    >Cancel</Button
                >
            </div>
        </Form>

        <p
            v-if="goals.current.length === 0 && !editing"
            class="text-sm text-muted-foreground"
        >
            No goals set for this month.
        </p>

        <ul class="space-y-4">
            <li
                v-for="goal in goals.current"
                :key="goal.metric"
                data-test="goal"
                :data-metric="goal.metric"
            >
                <div class="flex items-baseline justify-between gap-2 text-sm">
                    <span class="font-medium">{{ label(goal.metric) }}</span>
                    <span class="text-muted-foreground tabular-nums">
                        {{ format(goal.metric, goal.progress) }} of
                        {{ format(goal.metric, goal.target) }} ·
                        <span
                            :class="
                                goal.percent >= 100
                                    ? 'font-medium text-green-600 dark:text-green-400'
                                    : ''
                            "
                            data-test="goal-percent"
                            >{{ Math.floor(goal.percent) }}%</span
                        >
                    </span>
                </div>
                <div
                    class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-label="`${label(goal.metric)} goal`"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="Math.min(100, Math.floor(goal.percent))"
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            goal.percent >= 100
                                ? 'bg-green-500'
                                : 'bg-amber-500'
                        "
                        :style="{ width: `${Math.min(100, goal.percent)}%` }"
                    />
                </div>
                <ul
                    v-if="goal.contributors.length"
                    class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    :aria-label="`${label(goal.metric)} contributions`"
                >
                    <li
                        v-for="(c, i) in goal.contributors"
                        :key="i"
                        data-test="contributor"
                    >
                        <Link
                            v-if="c.driver.username"
                            :href="showDriver(c.driver.username)"
                            class="text-foreground hover:underline"
                            >{{ c.driver.name }}</Link
                        >
                        <span v-else>{{ c.driver.name }}</span>
                        {{ format(goal.metric, c.value) }}
                        ({{ Math.round(c.percent) }}%)
                    </li>
                </ul>
            </li>
        </ul>

        <div v-if="goals.history.length" class="mt-5">
            <h3 class="mb-2 text-sm font-medium">Past months</h3>
            <ul class="divide-y text-sm" data-test="goal-history">
                <li
                    v-for="month in goals.history"
                    :key="month.month"
                    class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:gap-4"
                    data-test="goal-month"
                >
                    <span class="w-36 shrink-0 font-medium">{{
                        monthName(month.month)
                    }}</span>
                    <span
                        v-for="g in month.goals"
                        :key="g.metric"
                        class="inline-flex items-center gap-1 tabular-nums"
                        :data-hit="g.hit ? 'yes' : 'no'"
                    >
                        <Check
                            v-if="g.hit"
                            class="size-4 text-green-600 dark:text-green-400"
                            aria-label="Hit"
                        />
                        <X
                            v-else
                            class="size-4 text-red-600 dark:text-red-400"
                            aria-label="Missed"
                        />
                        {{ label(g.metric) }}
                        {{ format(g.metric, g.achieved) }} /
                        {{ format(g.metric, g.target) }}
                    </span>
                </li>
            </ul>
        </div>
    </Panel>
</template>
