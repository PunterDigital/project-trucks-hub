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
                <strong>Pictures you upload:</strong> a profile picture, and a
                company logo and banner if you run a company. We keep a resized
                copy only (the original file and anything inside it, such as
                camera or location details, is thrown away). They're public, and
                replacing or removing one deletes the old copy.
            </li>
            <li>
                <strong>Synced jobs:</strong> only once you link the app and
                only jobs it records after that. Each job has the truck, cities
                and companies, cargo, weight, income, distance, duration (real
                and in-game), average speed, fuel used, damage, start and end
                times, and whether it was delivered, late or cancelled. With the
                game's own telemetry plugin it also has what the game paid, the
                XP earned, cargo damage, the total of tolls, fines and fares on
                the job, and any cancellation penalty.
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
                your company rank (worked out from the jobs you drove for it, or
                set by an owner or manager), and applications you sent (your
                message and your answers to the company's questions) or decided
                on. If your company sets a monthly minimum, its owner and
                managers see your progress towards it and the date of your last
                job for the company, worked out from those jobs (nothing extra
                is stored).
            </li>
            <li>
                <strong>Linked apps:</strong> the name of each linked copy of
                ProjectTrucks and when it was last used. The app's access token
                is stored only as a hash.
            </li>
            <li>
                <strong>Kudos and comments:</strong> the kudos you give and the
                comments you write on jobs, with when you wrote them.
            </li>
            <li>
                <strong>Follows:</strong> the drivers you follow and when you
                started, and which home feed tab you last picked. Profiles show
                only how many followers a driver has and how many drivers they
                follow, not who.
            </li>
            <li>
                <strong>Events:</strong> events you create for your company and
                your answers (going, maybe, not going). Anyone can see who is
                going to an event, except drivers with a private profile, who
                show as "Private driver" to people outside their company.
            </li>
            <li>
                <strong>Company news:</strong> news posts you write for your
                company as an owner or manager. A public post shows to everyone
                on the company page, a members-only post to the company's
                members, with your name unless your profile is private.
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
                Company owners and managers see applications to their company,
                with the message and answers.
            </li>
            <li>
                <strong>Company Discord:</strong> if your company's owner or a
                manager connects a Discord webhook, your new jobs (route, cargo,
                distance, truck), your joining and the company's new events are
                posted to their Discord channel with your name, even if your
                profile is private. A news post goes there too when its author
                ticks "Also post to Discord".
            </li>
            <li>
                <strong>Comments and kudos</strong> are shown to everyone who
                can see the job, with your name, even if your own profile is
                private. The job's driver can delete any comment on it, and you
                can delete your own.
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
                <strong>Discord</strong>: only when a company connects a webhook
                (see above). The Hub sends the posts to Discord's servers;
                Discord's own privacy policy covers them from there.
            </li>
            <li>
                <strong>Sentry</strong>: when the site hits an error, a report
                goes to Sentry (Functional Software, Inc.) so we can fix it: the
                error, the page address and your browser, plus your account
                number if you're signed in. No name, email, IP address or
                cookies.
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
            company memberships, applications, kudos, comments and follows
            straight away. Deleting a job also removes the kudos and comments on
            it.
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
