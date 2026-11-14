<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import CompanyForm from '@/components/hub/CompanyForm.vue';
import { Button } from '@/components/ui/button';
import { create, index, store } from '@/routes/companies';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Companies', href: index() },
            { title: 'New company', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New company" />

    <div class="max-w-xl p-4">
        <h1 class="mb-1 text-xl font-semibold">Start a company</h1>
        <p class="mb-6 text-sm text-muted-foreground">
            You'll be its owner. Jobs your drivers deliver count towards the
            company's stats and leaderboard place.
        </p>
        <Form
            v-bind="store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <CompanyForm :errors="errors" />
            <Button :disabled="processing" data-test="create-company"
                >Create company</Button
            >
        </Form>
    </div>
</template>
