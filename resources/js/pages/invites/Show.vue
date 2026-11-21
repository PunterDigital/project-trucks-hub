<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import CompanyLogo from '@/components/hub/CompanyLogo.vue';
import Panel from '@/components/hub/Panel.vue';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/format';
import { login, register } from '@/routes';
import { index, show as showCompany } from '@/routes/companies';
import { accept } from '@/routes/invites';
import type { CompanySummary } from '@/types/hub';

// A company invite link (#5221): who it's from and a Join button when it still works.
defineProps<{
    token: string;
    company:
        | (CompanySummary & { description: string | null; members: number })
        | null;
    problem: string | null;
    viewer: {
        signedIn: boolean;
        memberHere: boolean;
        otherCompany: CompanySummary | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Companies', href: index() }] },
});
</script>

<template>
    <Head title="Company invite" />

    <div class="max-w-xl p-4">
        <Panel title="Company invite">
            <div class="flex flex-col gap-4" data-test="invite-page">
                <div v-if="company" class="flex items-center gap-3">
                    <CompanyLogo :company="company" />
                    <div>
                        <Link
                            :href="showCompany(company.slug)"
                            class="font-semibold hover:underline"
                        >
                            <span class="text-muted-foreground"
                                >[{{ company.tag }}]</span
                            >
                            {{ company.name }}
                        </Link>
                        <p class="text-sm text-muted-foreground">
                            {{ company.members }}
                            {{ plural(company.members, 'member') }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="company?.description"
                    class="text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ company.description }}
                </p>

                <p
                    v-if="problem"
                    class="rounded-md border border-red-500/40 p-3 text-sm"
                    role="alert"
                    data-test="invite-problem"
                >
                    {{ problem }}
                </p>
                <template v-else-if="company">
                    <p v-if="viewer.memberHere" class="text-sm">
                        You're already in this company.
                    </p>
                    <p
                        v-else-if="viewer.otherCompany"
                        class="text-sm"
                        data-test="invite-other-company"
                    >
                        You're in
                        <Link
                            :href="showCompany(viewer.otherCompany.slug)"
                            class="font-medium hover:underline"
                            >{{ viewer.otherCompany.name }}</Link
                        >. Leave it first to join {{ company.name }}.
                    </p>
                    <template v-else-if="viewer.signedIn">
                        <p class="text-sm">
                            You've been invited to join {{ company.name }}.
                        </p>
                        <Form
                            v-bind="accept.form(token)"
                            v-slot="{ processing }"
                        >
                            <Button
                                :disabled="processing"
                                data-test="accept-invite"
                                >Join {{ company.name }}</Button
                            >
                        </Form>
                    </template>
                    <template v-else>
                        <p class="text-sm">
                            You've been invited to join {{ company.name }}. Log
                            in or create an account to accept: you'll come back
                            here.
                        </p>
                        <div class="flex gap-2">
                            <Button as-child>
                                <Link :href="login()">Log in</Link>
                            </Button>
                            <Button as-child variant="outline">
                                <Link :href="register()"
                                    >Create an account</Link
                                >
                            </Button>
                        </div>
                    </template>
                </template>
            </div>
        </Panel>
    </div>
</template>
