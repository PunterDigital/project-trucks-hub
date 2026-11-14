<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
// Settings pages are behind auth.
const user = computed(() => page.props.auth.user!);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Your name, username, email and who can see your profile"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="username">Username</Label>
                <Input
                    id="username"
                    class="mt-1 block w-full"
                    name="username"
                    :default-value="user.username ?? ''"
                    required
                    placeholder="big-rig-dave"
                />
                <p class="text-xs text-muted-foreground">
                    Your profile address: /drivers/{{
                        user.username ?? 'username'
                    }}
                </p>
                <InputError class="mt-2" :message="errors.username" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <label class="flex items-start gap-3 rounded-lg border p-3">
                <input type="hidden" name="profile_private" value="0" />
                <input
                    type="checkbox"
                    name="profile_private"
                    value="1"
                    :checked="user.profile_private"
                    class="mt-1"
                    data-test="profile-private"
                />
                <span>
                    <span class="block text-sm font-medium"
                        >Private profile</span
                    >
                    <span class="block text-xs text-muted-foreground">
                        Only you and members of your company see your profile
                        and jobs. You leave the public feed and the driver
                        leaderboard; your jobs still count for your company.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-lg border p-3">
                <input type="hidden" name="show_on_map" value="0" />
                <input
                    type="checkbox"
                    name="show_on_map"
                    value="1"
                    :checked="page.props.showOnMap !== false"
                    class="mt-1"
                    data-test="show-on-map"
                />
                <span>
                    <span class="block text-sm font-medium"
                        >Show me on the live map</span
                    >
                    <span class="block text-xs text-muted-foreground">
                        While you drive, your truck appears on the Hub's live
                        map. Off: the Hub doesn't store your position at all.
                        Private profiles never appear either way.
                    </span>
                </span>
            </label>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser :has-password="page.props.hasPassword !== false" />
</template>
