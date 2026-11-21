<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/companies/ranks';
import type { CompanyRank } from '@/types/hub';

// Same limit as App\Support\CompanyRanks::MAX.
const MAX = 12;

// A new row gets the next colour, so a fresh list isn't all one shade.
const PALETTE = [
    '#64748b',
    '#22c55e',
    '#0ea5e9',
    '#8b5cf6',
    '#f59e0b',
    '#ef4444',
];

const props = defineProps<{
    slug: string;
    ranks: CompanyRank[];
}>();

type Row = { id: number | null; name: string; color: string; min_km: string };

function toRows(ranks: CompanyRank[]): Row[] {
    return ranks.map((r) => ({
        id: r.id,
        name: r.name,
        color: r.color,
        min_km: String(r.min_km),
    }));
}

const form = useForm<{ rows: Row[] }>({ rows: toRows(props.ranks) });

function add() {
    const last = form.rows[form.rows.length - 1];
    const next = last ? (Number(last.min_km) || 0) + 1000 : 0;
    form.rows.push({
        id: null,
        name: '',
        color: PALETTE[form.rows.length % PALETTE.length],
        min_km: String(next),
    });
}

function save() {
    form.transform((data) => ({
        ranks: data.rows.map((r) => ({
            id: r.id,
            name: r.name,
            color: r.color,
            min_km: r.min_km === '' ? null : Number(r.min_km),
        })),
    })).put(update(props.slug).url, {
        preserveScroll: true,
        // The form keeps its state across the redirect: take the saved list (new ids, lowest
        // first), or the next save would send the new rows again without their ids.
        onSuccess: (page) => {
            form.rows = toRows(page.props.ranks as CompanyRank[]);
            form.defaults();
        },
    });
}

function error(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `ranks.${index}.${field}`
    ];
}

function listError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).ranks;
}
</script>

<template>
    <section id="ranks" class="space-y-4">
        <div>
            <h2 class="font-semibold">Ranks</h2>
            <p class="text-sm text-muted-foreground">
                Up to {{ MAX }} ranks. A member moves up when the jobs they've
                driven for this company (jobs that count, as on the
                leaderboards) reach a rank's distance, and the Hub tells them.
                You can pin a member's rank on the company page: a pin is the
                lowest rank they'll have, and driving can still move them
                higher.
            </p>
        </div>

        <form class="space-y-3" @submit.prevent="save">
            <p
                v-if="form.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No ranks: members only show their role.
            </p>

            <fieldset
                v-for="(row, index) in form.rows"
                :key="index"
                class="flex flex-wrap items-start gap-3 rounded-xl border p-3"
                data-test="rank-row"
            >
                <legend class="sr-only">Rank {{ index + 1 }}</legend>
                <div class="grid min-w-40 flex-1 gap-1">
                    <Label :for="`rank-name-${index}`">Name</Label>
                    <Input
                        :id="`rank-name-${index}`"
                        v-model="row.name"
                        maxlength="40"
                        placeholder="Senior driver"
                    />
                    <InputError :message="error(index, 'name')" />
                </div>
                <div class="grid w-36 gap-1">
                    <Label :for="`rank-km-${index}`">From (km)</Label>
                    <Input
                        :id="`rank-km-${index}`"
                        v-model="row.min_km"
                        type="number"
                        min="0"
                        step="1"
                        inputmode="numeric"
                    />
                    <InputError :message="error(index, 'min_km')" />
                </div>
                <div class="grid gap-1">
                    <Label :for="`rank-color-${index}`">Colour</Label>
                    <input
                        :id="`rank-color-${index}`"
                        v-model="row.color"
                        type="color"
                        class="h-9 w-14 cursor-pointer rounded-md border border-input bg-transparent p-1"
                    />
                    <InputError :message="error(index, 'color')" />
                </div>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="mt-5"
                    :aria-label="`Remove rank ${index + 1}`"
                    @click="form.rows.splice(index, 1)"
                    ><Trash2 class="size-4"
                /></Button>
            </fieldset>

            <InputError :message="listError()" />

            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.rows.length >= MAX"
                    @click="add"
                    ><Plus class="size-4" /> Add a rank</Button
                >
                <Button type="submit" :disabled="form.processing"
                    >Save ranks</Button
                >
            </div>
        </form>
    </section>
</template>
