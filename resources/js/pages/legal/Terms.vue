<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { privacy, terms } from '@/routes';

defineProps<{ contactEmail: string | null; updated: string }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Terms', href: terms() }] },
});
</script>

<template>
    <Head title="Terms of use" />

    <article class="legal" data-test="terms">
        <h1>Terms of use</h1>
        <p class="text-sm text-muted-foreground">Last updated {{ updated }}</p>

        <p>
            ProjectTrucks Hub is a free hobby site run by PunterDigital for
            drivers who use the ProjectTrucks desktop app. By creating an
            account or linking the app you agree to these terms. They're short
            on purpose.
        </p>

        <h2>Your account</h2>
        <ul>
            <li>
                Keep your sign-in safe. You're responsible for what happens on
                your account and on the copies of ProjectTrucks you link to it.
            </li>
            <li>
                Pick a name, username, company name and description that aren't
                offensive, hateful or pretending to be someone else.
            </li>
            <li>
                You can delete your account at any time in Settings → Profile.
            </li>
        </ul>

        <h2>Fair play</h2>
        <ul>
            <li>
                Jobs should come from real driving recorded by ProjectTrucks (or
                entered by hand honestly). Don't fake, edit or replay jobs to
                climb the leaderboards.
            </li>
            <li>
                Jobs that look impossible may be flagged and left out of stats
                and leaderboards. Staff may hide jobs, remove content, or
                suspend accounts that break these terms.
            </li>
            <li>
                Don't attack, overload or scrape the site, or use the sync API
                for anything other than ProjectTrucks.
            </li>
        </ul>

        <h2>Your content</h2>
        <p>
            You keep ownership of what you sync and write. You let us store and
            show it on the Hub as described in the
            <Link :href="privacy()">privacy page</Link>, until you delete it.
        </p>

        <h2>No guarantees</h2>
        <p>
            The Hub is provided as it is, for fun. It may be down, change, lose
            data or close, and we're not liable for any loss that comes from
            using it, as far as the law allows. Keep your own records in the
            app.
        </p>

        <h2>Not affiliated</h2>
        <p>
            ProjectTrucks and the Hub aren't made by or affiliated with SCS
            Software or Valve. Euro Truck Simulator 2, American Truck Simulator
            and Steam are trademarks of their owners.
        </p>

        <h2>Changes</h2>
        <p>
            We may update these terms; the date at the top changes when we do.
            Big changes will be announced on the site.
        </p>

        <h2>Contact</h2>
        <p v-if="contactEmail">
            Email <a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a>.
        </p>
        <p v-else>
            Open an issue on
            <a href="https://github.com/PunterDigital/project-trucks-hub"
                >the Hub's GitHub repository</a
            >.
        </p>
    </article>
</template>

<style scoped src="./legal.css"></style>
