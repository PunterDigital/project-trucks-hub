<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Recruitment } from '@/types/hub';

defineProps<{
    errors: Record<string, string | undefined>;
    company?: {
        name: string;
        tag: string;
        description: string | null;
        recruitment: Recruitment;
    };
}>();

const recruitmentOptions: {
    value: Recruitment;
    label: string;
    hint: string;
}[] = [
    {
        value: 'apply',
        label: 'Applications',
        hint: 'Drivers apply; an owner or manager decides.',
    },
    { value: 'open', label: 'Open', hint: 'Anyone can join straight away.' },
    { value: 'closed', label: 'Closed', hint: 'No new members.' },
];
</script>

<template>
    <div class="grid gap-2">
        <Label for="name">Company name</Label>
        <Input
            id="name"
            name="name"
            required
            maxlength="60"
            :default-value="company?.name"
            placeholder="Nordic Haulage"
        />
        <InputError :message="errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="tag">Tag</Label>
        <Input
            id="tag"
            name="tag"
            required
            maxlength="5"
            class="w-32 uppercase"
            :default-value="company?.tag"
            placeholder="NRDC"
        />
        <p class="text-xs text-muted-foreground">
            2–5 letters or numbers, shown next to members' names.
        </p>
        <InputError :message="errors.tag" />
    </div>

    <div class="grid gap-2">
        <Label for="description">About</Label>
        <textarea
            id="description"
            name="description"
            rows="4"
            maxlength="2000"
            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :value="company?.description ?? ''"
            placeholder="Where you drive, what you haul, how often you convoy…"
        />
        <InputError :message="errors.description" />
    </div>

    <fieldset class="grid gap-2">
        <legend class="mb-1 text-sm font-medium">Recruitment</legend>
        <label
            v-for="option in recruitmentOptions"
            :key="option.value"
            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-checked:border-primary"
        >
            <input
                type="radio"
                name="recruitment"
                :value="option.value"
                :checked="(company?.recruitment ?? 'apply') === option.value"
                class="mt-1"
            />
            <span>
                <span class="block text-sm font-medium">{{
                    option.label
                }}</span>
                <span class="block text-xs text-muted-foreground">{{
                    option.hint
                }}</span>
            </span>
        </label>
        <InputError :message="errors.recruitment" />
    </fieldset>
</template>
