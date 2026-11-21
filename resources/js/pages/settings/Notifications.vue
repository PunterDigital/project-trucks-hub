<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit, update } from '@/routes/notification-settings';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Notifications', href: edit() }] },
});

type Setting = {
    type: string;
    label: string;
    description: string;
    enabled: boolean;
};

const props = defineProps<{
    emails: Setting[];
    // Bell notices that can be switched off (#5224): company news so far.
    bell: Setting[];
}>();

const form = useForm({
    enabled: props.emails.filter((e) => e.enabled).map((e) => e.type),
    bell: props.bell.filter((b) => b.enabled).map((b) => b.type),
});

function toggle(list: 'enabled' | 'bell', type: string, on: boolean) {
    form[list] = on
        ? [...new Set([...form[list], type])]
        : form[list].filter((t) => t !== type);
}

function save() {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <form class="space-y-8" @submit.prevent="save">
        <div v-if="bell.length > 0" class="space-y-4">
            <Heading
                variant="small"
                title="Bell notifications"
                description="Shown under the bell at the top of the page. Everything about your own account, jobs and applications always shows."
            />
            <label
                v-for="item in bell"
                :key="item.type"
                :for="`bell-${item.type}`"
                class="flex items-start gap-3 rounded-xl border p-4"
            >
                <Checkbox
                    :id="`bell-${item.type}`"
                    :aria-label="item.label"
                    :model-value="form.bell.includes(item.type)"
                    class="mt-0.5"
                    @update:model-value="
                        (v) => toggle('bell', item.type, v === true)
                    "
                />
                <span class="text-sm">
                    <span class="block font-medium">{{ item.label }}</span>
                    <span
                        v-if="item.description"
                        class="block text-muted-foreground"
                        >{{ item.description }}</span
                    >
                </span>
            </label>
        </div>

        <div class="space-y-4">
            <Heading
                variant="small"
                title="Email notifications"
                description="Everything also shows under the bell at the top of the page. Untick what you don't want by email."
            />
            <label
                v-for="email in emails"
                :key="email.type"
                :for="`email-${email.type}`"
                class="flex items-start gap-3 rounded-xl border p-4"
            >
                <Checkbox
                    :id="`email-${email.type}`"
                    :aria-label="email.label"
                    :model-value="form.enabled.includes(email.type)"
                    class="mt-0.5"
                    @update:model-value="
                        (v) => toggle('enabled', email.type, v === true)
                    "
                />
                <span class="text-sm">
                    <span class="block font-medium">{{ email.label }}</span>
                    <span
                        v-if="email.description"
                        class="block text-muted-foreground"
                        >{{ email.description }}</span
                    >
                </span>
            </label>
        </div>

        <Button type="submit" :disabled="form.processing">Save</Button>
    </form>
</template>
