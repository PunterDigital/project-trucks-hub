<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLocalInput } from '@/lib/format';
import { index as companies, show as showCompany } from '@/routes/companies';
import { store, truckersmp } from '@/routes/companies/events';
import { update } from '@/routes/events';
import type { CompanySummary, EventSummary } from '@/types/hub';

// Create or edit a company event (#4648). The start time is typed in local time and sent as
// UTC, so every viewer sees it in their own time zone. "Fill from TruckersMP" (#5228) fills
// the fields from a TruckersMP event; they stay editable.
const props = defineProps<{
    company: CompanySummary;
    event:
        | (EventSummary & {
              start_company: string | null;
              dest_company: string | null;
              route_notes: string | null;
              truckersmp_url: string | null;
              route_image_url: string | null;
          })
        | null;
    games: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Companies', href: companies() }] },
});

const fields = reactive({
    title: props.event?.title ?? '',
    game: (props.event?.game ?? 'ets2') as string,
    server: props.event?.server ?? '',
    start_city: props.event?.start_city ?? '',
    start_company: props.event?.start_company ?? '',
    dest_city: props.event?.dest_city ?? '',
    dest_company: props.event?.dest_company ?? '',
    truckersmp_url: props.event?.truckersmp_url ?? '',
    route_image_url: props.event?.route_image_url ?? '',
});
const local = ref(props.event ? toLocalInput(props.event.starts_at) : '');
const startsAt = computed(() => {
    if (local.value === '') {
        return '';
    }

    const d = new Date(local.value);

    return Number.isNaN(d.getTime()) ? '' : d.toISOString();
});
// In the script, not the template: vue-tsc 3.3.13 breaks on a URL in a template attribute.
const linkPlaceholder = 'https://truckersmp.com/events/12345';
const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const action = computed(() =>
    props.event ? update.form(props.event.id) : store.form(props.company.slug),
);
const inputClass =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type Filled = {
    truckersmp_url: string;
    title: string;
    starts_at: string | null;
    game: string | null;
    server: string | null;
    start_city: string | null;
    start_company: string | null;
    dest_city: string | null;
    dest_company: string | null;
    route_image_url: string | null;
};

const filling = ref(false);
const fillError = ref<string | null>(null);
const filled = ref(false);

// Asks the Hub, which asks TruckersMP (5 s timeout, cached 10 min). A failure leaves the form
// as it was and says why.
async function fillFromTruckersMp() {
    fillError.value = null;
    filled.value = false;

    if (fields.truckersmp_url.trim() === '') {
        fillError.value = 'Paste the link to the event on TruckersMP first.';

        return;
    }

    filling.value = true;

    try {
        const res = await fetch(
            truckersmp.url(props.company.slug, {
                query: { url: fields.truckersmp_url.trim() },
            }),
            { headers: { Accept: 'application/json' } },
        );
        const body = (await res.json().catch(() => null)) as {
            fields?: Filled;
            message?: string;
        } | null;

        if (!res.ok || !body?.fields) {
            fillError.value =
                body?.message ??
                'TruckersMP didn’t answer. Try again in a minute, or fill the form in yourself.';

            return;
        }

        const f = body.fields;
        fields.truckersmp_url = f.truckersmp_url;
        fields.title = f.title;
        fields.game = f.game ?? fields.game;
        fields.server = f.server ?? '';
        fields.start_city = f.start_city ?? '';
        fields.start_company = f.start_company ?? '';
        fields.dest_city = f.dest_city ?? '';
        fields.dest_company = f.dest_company ?? '';
        fields.route_image_url = f.route_image_url ?? '';

        if (f.starts_at) {
            local.value = toLocalInput(f.starts_at);
        }

        filled.value = true;
    } catch {
        fillError.value =
            'TruckersMP didn’t answer. Try again in a minute, or fill the form in yourself.';
    } finally {
        filling.value = false;
    }
}
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
            <div class="grid gap-2 rounded-xl border p-4">
                <Label for="truckersmp_url">TruckersMP event link</Label>
                <div class="flex gap-2">
                    <Input
                        id="truckersmp_url"
                        v-model="fields.truckersmp_url"
                        name="truckersmp_url"
                        maxlength="200"
                        :placeholder="linkPlaceholder"
                        autocomplete="off"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="filling"
                        data-test="fill-truckersmp"
                        @click="fillFromTruckersMp"
                        ><Download class="size-4" /> Fill from
                        TruckersMP</Button
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    Optional. Announced the convoy on TruckersMP? Paste its link
                    to fill the form, then change anything you like. The event
                    page links to it.
                </p>
                <p
                    v-if="filled"
                    class="text-xs text-green-600 dark:text-green-400"
                    role="status"
                    data-test="truckersmp-filled"
                >
                    Filled from TruckersMP. Check the details, then save.
                </p>
                <InputError
                    :message="fillError ?? errors.truckersmp_url"
                    data-test="truckersmp-error"
                />
            </div>

            <div class="grid gap-2">
                <Label for="title">Title</Label>
                <Input
                    id="title"
                    v-model="fields.title"
                    name="title"
                    required
                    maxlength="120"
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
                    <select
                        id="game"
                        v-model="fields.game"
                        name="game"
                        :class="inputClass"
                    >
                        <option
                            v-for="(label, value) in games"
                            :key="value"
                            :value="value"
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
                        v-model="fields.server"
                        name="server"
                        maxlength="80"
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
                        v-model="fields.start_city"
                        name="start_city"
                        required
                        maxlength="80"
                        placeholder="Calais"
                    />
                    <InputError :message="errors.start_city" />
                </div>
                <div class="grid gap-2">
                    <Label for="start_company">Start company</Label>
                    <Input
                        id="start_company"
                        v-model="fields.start_company"
                        name="start_company"
                        maxlength="80"
                        placeholder="Optional"
                    />
                    <InputError :message="errors.start_company" />
                </div>
                <div class="grid gap-2">
                    <Label for="dest_city">Destination city</Label>
                    <Input
                        id="dest_city"
                        v-model="fields.dest_city"
                        name="dest_city"
                        required
                        maxlength="80"
                        placeholder="Duisburg"
                    />
                    <InputError :message="errors.dest_city" />
                </div>
                <div class="grid gap-2">
                    <Label for="dest_company">Destination company</Label>
                    <Input
                        id="dest_company"
                        v-model="fields.dest_company"
                        name="dest_company"
                        maxlength="80"
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
                <Label for="route_image_url">Route map image</Label>
                <Input
                    id="route_image_url"
                    v-model="fields.route_image_url"
                    name="route_image_url"
                    maxlength="500"
                    placeholder="Filled from TruckersMP"
                    autocomplete="off"
                />
                <p class="text-xs text-muted-foreground">
                    A route image on TruckersMP (static.truckersmp.com). The
                    event page shows it.
                </p>
                <InputError :message="errors.route_image_url" />
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
