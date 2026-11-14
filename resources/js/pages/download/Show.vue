<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, ExternalLink, ShieldCheck } from '@lucide/vue';
import Panel from '@/components/hub/Panel.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { download } from '@/routes';
import { windows } from '@/routes/download';

type Installer = {
    kind: 'exe' | 'msi';
    name: string;
    size: number;
    url: string;
    sha256: string | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Download', href: download() }] },
});

const props = defineProps<{
    release: {
        version: string;
        published_at: string | null;
        notes_url: string;
        installers: Installer[];
    } | null;
    releasesPage: string;
}>();

const exe = props.release?.installers.find((i) => i.kind === 'exe') ?? null;
const msi = props.release?.installers.find((i) => i.kind === 'msi') ?? null;

function megabytes(bytes: number): string {
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <Head title="Download ProjectTrucks" />

    <div class="flex flex-col gap-4 p-4">
        <section
            class="flex flex-col gap-6 rounded-xl border bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white sm:p-8 md:flex-row md:items-center"
        >
            <img src="/favicon.svg" alt="" class="size-20 shrink-0" />
            <div class="flex-1 space-y-2">
                <h1 class="text-2xl font-semibold sm:text-3xl">
                    Download ProjectTrucks
                </h1>
                <p class="max-w-xl text-slate-300">
                    The free Euro Truck Simulator 2 delivery tracker for
                    Windows. It records every job and kilometre from the game to
                    a database on your own PC.
                </p>
                <p
                    v-if="release"
                    class="text-sm text-slate-400"
                    data-test="release-version"
                >
                    Version {{ release.version }}
                    <template v-if="release.published_at">
                        · released
                        {{ formatDate(release.published_at) }}</template
                    >
                    ·
                    <a
                        :href="release.notes_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline hover:text-white"
                        >what's new</a
                    >
                </p>
            </div>
            <div class="flex flex-col gap-2 md:items-end">
                <template v-if="exe">
                    <Button
                        as-child
                        size="lg"
                        class="bg-amber-500 text-slate-950 hover:bg-amber-400"
                    >
                        <a :href="windows().url" data-test="download-exe"
                            ><Download class="size-4" /> Download for Windows</a
                        >
                    </Button>
                    <span class="text-xs text-slate-400"
                        >64-bit installer · {{ megabytes(exe.size) }}</span
                    >
                    <a
                        v-if="msi"
                        :href="windows('msi').url"
                        class="text-xs text-slate-300 underline hover:text-white"
                        data-test="download-msi"
                        >or the .msi package ({{ megabytes(msi.size) }})</a
                    >
                </template>
                <Button v-else as-child size="lg" variant="secondary">
                    <a
                        :href="releasesPage"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="download-fallback"
                        ><ExternalLink class="size-4" /> Get it from GitHub</a
                    >
                </Button>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Install it" class="lg:col-span-2">
                <ol class="list-decimal space-y-3 pl-5 text-sm">
                    <li>
                        <strong>Run the installer.</strong> ProjectTrucks isn't
                        code-signed yet, so Windows SmartScreen may say
                        <em>"Windows protected your PC"</em>: click
                        <strong>More info</strong>, then
                        <strong>Run anyway</strong>.
                    </li>
                    <li>
                        <strong>Open ProjectTrucks</strong> and go to
                        <strong>Settings</strong>. It finds Euro Truck Simulator
                        2 through Steam; click
                        <strong>Install plugin</strong> so it can read the
                        game's telemetry.
                    </li>
                    <li>
                        <strong>Turn on auto-capture</strong>, start ETS2 and
                        take a job. The header shows <em>Tracking</em> once it
                        can see the game.
                    </li>
                    <li>
                        <strong>Link it to the Hub</strong> to share your jobs
                        here: Settings → ProjectTrucks Hub → Link account, then
                        approve the code on this site. Hub syncing arrives in an
                        upcoming ProjectTrucks update; until then everything
                        stays on your PC.
                    </li>
                </ol>
            </Panel>

            <div class="flex flex-col gap-4">
                <Panel title="Requirements">
                    <ul class="list-disc space-y-1 pl-5 text-sm">
                        <li>Windows 10 or 11, 64-bit</li>
                        <li>Euro Truck Simulator 2 (PC)</li>
                        <li>
                            Updates: the app tells you when a new version is out
                            and updates in one click.
                        </li>
                    </ul>
                </Panel>

                <Panel v-if="exe?.sha256 || msi?.sha256" title="Checksums">
                    <p
                        class="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <ShieldCheck class="size-3.5" /> SHA-256, as published
                        on GitHub
                    </p>
                    <dl class="space-y-2 text-xs">
                        <template v-for="i in [exe, msi]" :key="i?.name">
                            <div v-if="i?.sha256">
                                <dt class="font-medium">{{ i.name }}</dt>
                                <dd
                                    class="font-mono break-all text-muted-foreground"
                                    data-test="sha256"
                                >
                                    {{ i.sha256 }}
                                </dd>
                            </div>
                        </template>
                    </dl>
                </Panel>
            </div>
        </div>
    </div>
</template>
