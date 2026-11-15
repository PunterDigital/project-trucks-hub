<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import CompanyLogo from '@/components/hub/CompanyLogo.vue';
import Pagination from '@/components/hub/Pagination.vue';
import RecruitmentBadge from '@/components/hub/RecruitmentBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatKm } from '@/lib/format';
import { create, index, show } from '@/routes/companies';
import type { CompanySummary, Paginated, Recruitment } from '@/types/hub';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Companies', href: index() }] },
});

defineProps<{
    companies: Paginated<
        CompanySummary & {
            description: string;
            recruitment: Recruitment;
            members: number;
            distance_km: number;
        }
    >;
    search: string;
    canCreate: boolean;
}>();
</script>

<template>
    <Head title="Companies" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <h1 class="flex-1 text-xl font-semibold">Companies</h1>
            <Form v-bind="index.form()" class="flex gap-2">
                <Input
                    name="search"
                    :default-value="search"
                    placeholder="Search by name or tag"
                    aria-label="Search companies"
                    class="w-56"
                />
                <Button type="submit" variant="outline" size="icon">
                    <Search class="size-4" /><span class="sr-only">Search</span>
                </Button>
            </Form>
            <Button v-if="canCreate" as-child>
                <Link :href="create()"
                    ><Plus class="size-4" /> New company</Link
                >
            </Button>
        </div>

        <p
            v-if="companies.data.length === 0"
            class="py-10 text-center text-sm text-muted-foreground"
        >
            No companies yet.
        </p>
        <ul v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="company in companies.data" :key="company.slug">
                <Link
                    :href="show(company.slug)"
                    class="flex h-full flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/40"
                    data-test="company-card"
                >
                    <div class="flex items-start justify-between gap-2">
                        <CompanyLogo :company="company" />
                        <h2 class="flex-1 font-semibold">
                            <span class="text-muted-foreground"
                                >[{{ company.tag }}]</span
                            >
                            {{ company.name }}
                        </h2>
                        <RecruitmentBadge :recruitment="company.recruitment" />
                    </div>
                    <p class="flex-1 text-sm text-muted-foreground">
                        {{ company.description || 'No description.' }}
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ company.members }}
                        {{ company.members === 1 ? 'member' : 'members' }} ·
                        {{ formatKm(company.distance_km) }} driven
                    </p>
                </Link>
            </li>
        </ul>

        <Pagination :links="companies.links" :last-page="companies.last_page" />
    </div>
</template>
