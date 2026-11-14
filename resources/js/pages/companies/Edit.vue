<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import CompanyForm from '@/components/hub/CompanyForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, update } from '@/routes/companies';
import type { CompanySummary, Recruitment } from '@/types/hub';

defineProps<{
    company: CompanySummary & {
        description: string | null;
        recruitment: Recruitment;
    };
    isOwner: boolean;
}>();
</script>

<template>
    <Head :title="`Edit ${company.name}`" />

    <div class="max-w-xl space-y-10 p-4">
        <div>
            <h1 class="mb-6 text-xl font-semibold">Edit {{ company.name }}</h1>
            <Form
                v-bind="update.form(company.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <CompanyForm :errors="errors" :company="company" />
                <Button :disabled="processing">Save</Button>
            </Form>
        </div>

        <section
            v-if="isOwner"
            class="space-y-4 rounded-xl border border-red-500/40 p-4"
        >
            <div>
                <h2 class="font-semibold">Delete company</h2>
                <p class="text-sm text-muted-foreground">
                    Removes the company and its roster. Members keep their jobs
                    on their own profiles. This can't be undone.
                </p>
            </div>
            <Form
                v-bind="destroy.form(company.slug)"
                class="space-y-3"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="confirm_name"
                        >Type <strong>{{ company.name }}</strong> to
                        confirm</Label
                    >
                    <Input
                        id="confirm_name"
                        name="confirm_name"
                        autocomplete="off"
                    />
                    <InputError :message="errors.confirm_name" />
                </div>
                <Button variant="destructive" :disabled="processing"
                    >Delete company</Button
                >
            </Form>
        </section>
    </div>
</template>
