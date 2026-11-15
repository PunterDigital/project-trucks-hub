<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { download, privacy, terms } from '@/routes';
import { edit as privacySettings } from '@/routes/privacy-settings';

defineProps<{ contactEmail: string | null; updated: string }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Privacy', href: privacy() }] },
});
</script>

<template>
    <Head title="Privacy" />

    <article class="legal" data-test="privacy-policy">
        <h1>Privacy</h1>
        <p class="text-sm text-muted-foreground">Last updated {{ updated }}</p>

        <p>
            ProjectTrucks Hub is where drivers using the ProjectTrucks desktop
            app share their Euro Truck Simulator 2 and American Truck Simulator
            jobs. It's a free hobby project run by PunterDigital. This page says
            what we keep, who can see it, and how to get it or delete it.
        </p>

        <h2>What we keep</h2>
        <ul>
            <li>
                <strong>Your account:</strong> name, username, email address, a
                hashed password (never the password itself), and if you use
                them, two-factor authentication secrets (encrypted) and the
                public keys of your passkeys.
            </li>
            <li>
                <strong>Steam:</strong> if you sign in with Steam, your Steam ID
                and your Steam avatar's address. We never see your Steam
                password.
            </li>
            <li>
                <strong>Synced jobs:</strong> only once you link the app and
                only jobs it records after that. Each job has the truck, cities
                and companies, cargo, weight, income, distance, duration,
                average speed, fuel used, damage, start and end times, and
                whether it was delivered, late or cancelled.
            </li>
            <li>
                <strong>Live status:</strong> while you drive, the app sends
                your truck, current job (cargo, from, to, progress) and your
                truck's position and heading in the game world. It counts as
                offline after two minutes without an update and is removed when
                you stop.
            </li>
            <li>
                <strong>Companies:</strong> which company you're in, your role,
                and applications you sent or decided on.
            </li>
            <li>
                <strong>Linked apps:</strong> the name of each linked copy of
                ProjectTrucks and when it was last used. The app's access token
                is stored only as a hash.
            </li>
            <li>
                <strong>Server logs:</strong> errors and requests, which can
                include your IP address, kept for a short time to keep the site
                running.
            </li>
            <li>
                <strong>Reports and moderation:</strong> when you report a
                profile, company or job we keep who sent it, the reason and any
                details you wrote. Only Hub staff see reports, and the person
                you reported isn't told who sent one. Staff actions (hiding a
                job, suspending an account and so on) are kept in a log with the
                staff member's name and reason.
            </li>
        </ul>

        <h2>What never leaves your PC</h2>
        <p>
            Your job notes, the app's settings, jobs you add by hand, jobs
            recorded before you linked the app, and anything you don't choose to
            sync. Unlinking the app (in the app or under Settings → Linked apps)
            stops syncing at once.
        </p>

        <h2>Who sees what</h2>
        <ul>
            <li>
                Profiles and jobs are public by default: anyone can see your
                name, username, avatar, company, stats and jobs.
            </li>
            <li>
                A <strong>private profile</strong> (Settings → Profile) hides
                you and your jobs from everyone except you and your company
                mates. Your jobs still count towards your company's totals.
            </li>
            <li>
                The <strong>live map</strong> shows public profiles that are
                driving right now: name, company, truck, current job and where
                the truck is in the game. Private profiles never appear, and
                "Show me on the live map" (Settings → Profile) turns it off: the
                Hub then stops storing your position at all.
            </li>
            <li>
                Your email address is never shown to anyone. Staff only use it
                to run the site.
            </li>
            <li>
                Company owners and managers see applications to their company.
            </li>
        </ul>

        <h2>Cookies</h2>
        <p>
            Only the ones the site needs: a session cookie that keeps you signed
            in, a security token against forged requests, and your theme and
            sidebar choices. No analytics, no advertising, no tracking, so
            there's no cookie banner.
        </p>

        <h2>Other services</h2>
        <ul>
            <li>
                <strong>Steam</strong> (Valve): signing in with Steam goes
                through Steam's own sign-in page. The "Sign in through Steam"
                buttons on the log-in, register and Connections pages are
                Valve's images, loaded by your browser from Steam's servers (no
                referrer sent). If the site has a Steam Web API key, we look up
                your Steam name and avatar once when you sign in.
            </li>
            <li>
                <strong>GitHub</strong>: the
                <Link :href="download()">download page</Link> asks GitHub which
                ProjectTrucks release is the latest, and installers download
                from GitHub.
            </li>
            <li>
                <strong>Bunny Fonts</strong>: the site's font loads from
                fonts.bunny.net, which doesn't log IP addresses or set cookies.
            </li>
            <li>
                Our hosting provider runs the server and our email provider
                sends account emails (verification, password resets, and the
                notification emails you keep on under Settings → Notifications).
            </li>
        </ul>
        <p>We don't sell or share your data with anyone else.</p>

        <h2>How long we keep it</h2>
        <p>
            Until you delete it. Under
            <Link :href="privacySettings()">Settings → Privacy</Link> you can
            download everything we hold about you as a file, or delete all your
            synced jobs and keep your account. Deleting your account (Settings →
            Profile) removes your profile, jobs, live status, linked apps,
            company memberships and applications straight away.
        </p>

        <h2>Questions</h2>
        <p v-if="contactEmail">
            Email
            <a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a> about
            anything on this page, including a copy or deletion of your data if
            you can't sign in.
        </p>
        <p v-else>
            Open an issue on
            <a href="https://github.com/PunterDigital/project-trucks-hub"
                >the Hub's GitHub repository</a
            >
            (don't post personal details there; we'll reply with a private way
            to reach us).
        </p>
        <p>See also the <Link :href="terms()">terms of use</Link>.</p>
    </article>
</template>

<style scoped src="./legal.css"></style>
