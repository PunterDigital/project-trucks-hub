<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { privacy } from '@/routes';
import { edit, exportMethod as exportData } from '@/routes/privacy-settings';
import { destroy as destroyJobs } from '@/routes/privacy-settings/jobs';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Privacy', href: edit() }] },
});

defineProps<{ jobCount: number; linkedApps: number }>();

const open = ref(false);
const deleting = ref(false);

function deleteJobs() {
    router.delete(destroyJobs().url, {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Head title="Privacy settings" />

    <h1 class="sr-only">Privacy settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Download your data"
            description="Everything the Hub holds about you (account, company, applications, linked apps, passkeys, live status and every synced job) as one JSON file"
        />
        <Button
            as="a"
            :href="exportData().url"
            variant="outline"
            data-test="export-data"
        >
            <Download class="h-4 w-4" />
            Download my data
        </Button>
        <p class="text-sm text-muted-foreground">
            What we keep and why is on the
            <Link :href="privacy()" class="underline underline-offset-4"
                >privacy page</Link
            >.
        </p>
    </div>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Delete synced jobs"
            description="Remove every job ProjectTrucks has synced to the Hub. Your account, company and linked apps stay."
        />
        <p class="text-sm" data-test="job-count">
            {{ jobCount === 1 ? '1 synced job' : `${jobCount} synced jobs` }}
            on the Hub.
        </p>

        <Dialog v-model:open="open">
            <DialogTrigger as-child>
                <Button
                    variant="destructive"
                    :disabled="jobCount === 0"
                    data-test="delete-jobs-button"
                    >Delete all synced jobs</Button
                >
            </DialogTrigger>
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Delete all synced jobs?</DialogTitle>
                    <DialogDescription>
                        This removes {{ jobCount }}
                        {{ jobCount === 1 ? 'job' : 'jobs' }} from the Hub, your
                        profile, your company's totals and the leaderboards. It
                        can't be undone. The jobs stay in ProjectTrucks on your
                        PC.
                        <template v-if="linkedApps > 0">
                            A linked app keeps syncing new jobs (and old ones
                            you edit there): unlink it under Linked apps to
                            stop.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="deleting"
                        data-test="confirm-delete-jobs"
                        @click="deleteJobs"
                        >Delete jobs</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
