<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLocalInput } from '@/lib/format';
import { index as companies, show as showCompany } from '@/routes/companies';
import { store } from '@/routes/companies/events';
import { update } from '@/routes/events';
import type { CompanySummary, EventSummary } from '@/types/hub';

// Create or edit a company event (#4648). The start time is typed in local time and sent as
// UTC, so every viewer sees it in their own time zone.
const props = defineProps<{
    company: CompanySummary;
    event:
        | (EventSummary & {
              start_company: string | null;
              dest_company: string | null;
              route_notes: string | null;
          })
        | null;
    games: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Companies', href: companies() }] },
});

const local = ref(props.event ? toLocalInput(props.event.starts_at) : '');
const startsAt = computed(() => {
    if (local.value === '') {
        return '';
    }

    const d = new Date(local.value);

    return Number.isNaN(d.getTime()) ? '' : d.toISOString();
});
const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const action = computed(() =>
    props.event ? update.form(props.event.id) : store.form(props.company.slug),
);
const inputClass =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Head :title="event ? 'Edit event' : 'New event'" />

    <div class="max-w-xl p-4">
        <h1 class="mb-1 text-xl font-semibold">
            {{ event ? 'Edit event' : 'New event' }}
        </h1>
        <p class="mb-6 text-sm text-muted-foreground">
            For
            <a
                :href="showCompany(company.slug).url"
                class="font-medium text-foreground hover:underline"
                >[{{ company.tag }}] {{ company.name }}</a
            >. Members can say whether they're coming.
        </p>
        <Form v-bind="action" class="space-y-5" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="title">Title</Label>
                <Input
                    id="title"
                    name="title"
                    required
                    maxlength="120"
                    :default-value="event?.title"
                    placeholder="Friday night convoy"
                />
                <InputError :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="starts_at_local">Start time</Label>
                <input
                    id="starts_at_local"
                    v-model="local"
                    type="datetime-local"
                    required
                    :class="inputClass"
                />
                <input type="hidden" name="starts_at" :value="startsAt" />
                <p class="text-xs text-muted-foreground">
                    Your time zone ({{ zone }}). Everyone else sees it in
                    theirs.
                </p>
                <InputError :message="errors.starts_at" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="game">Game</Label>
                    <select id="game" name="game" :class="inputClass">
                        <option
                            v-for="(label, value) in games"
                            :key="value"
                            :value="value"
                            :selected="(event?.game ?? 'ets2') === value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <InputError :message="errors.game" />
                </div>
                <div class="grid gap-2">
                    <Label for="server">Server</Label>
                    <Input
                        id="server"
                        name="server"
                        maxlength="80"
                        :default-value="event?.server ?? ''"
                        placeholder="TruckersMP Simulation 1"
                    />
                    <InputError :message="errors.server" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="start_city">Start city</Label>
                    <Input
                        id="start_city"
                        name="start_city"
                        required
                        maxlength="80"
                        :default-value="event?.start_city"
                        placeholder="Calais"
                    />
                    <InputError :message="errors.start_city" />
                </div>
                <div class="grid gap-2">
                    <Label for="start_company">Start company</Label>
                    <Input
                        id="start_company"
                        name="start_company"
                        maxlength="80"
                        :default-value="event?.start_company ?? ''"
                        placeholder="Optional"
                    />
                    <InputError :message="errors.start_company" />
                </div>
                <div class="grid gap-2">
                    <Label for="dest_city">Destination city</Label>
                    <Input
                        id="dest_city"
                        name="dest_city"
                        required
                        maxlength="80"
                        :default-value="event?.dest_city"
                        placeholder="Duisburg"
                    />
                    <InputError :message="errors.dest_city" />
                </div>
                <div class="grid gap-2">
                    <Label for="dest_company">Destination company</Label>
                    <Input
                        id="dest_company"
                        name="dest_company"
                        maxlength="80"
                        :default-value="event?.dest_company ?? ''"
                        placeholder="Optional"
                    />
                    <InputError :message="errors.dest_company" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="route_notes">Route notes</Label>
                <textarea
                    id="route_notes"
                    name="route_notes"
                    rows="4"
                    maxlength="2000"
                    :class="inputClass"
                    placeholder="Meet at the Calais service station. Fuel stop at Brussel."
                    :value="event?.route_notes ?? ''"
                />
                <InputError :message="errors.route_notes" />
            </div>

            <div class="grid gap-2">
                <Label for="max_drivers">Max drivers</Label>
                <Input
                    id="max_drivers"
                    name="max_drivers"
                    type="number"
                    min="1"
                    max="500"
                    class="w-32"
                    :default-value="event?.max_drivers ?? ''"
                    placeholder="No limit"
                />
                <InputError :message="errors.max_drivers" />
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input
                    type="checkbox"
                    name="open"
                    value="1"
                    class="mt-0.5"
                    :checked="event?.open ?? false"
                />
                <span>
                    <span class="font-medium">Open event</span>
                    <span class="block text-muted-foreground"
                        >Drivers outside the company can say they're coming
                        too.</span
                    >
                </span>
            </label>

            <Button :disabled="processing" data-test="save-event">{{
                event ? 'Save event' : 'Create event'
            }}</Button>
        </Form>
    </div>
</template>
