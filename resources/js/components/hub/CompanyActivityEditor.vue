<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/companies/activity';

// Monthly activity requirement (#5225). Same limits as App\Support\CompanyActivity.
defineProps<{
    slug: string;
    activity: { min_km: number | null; min_deliveries: number | null };
}>();
</script>

<template>
    <section id="activity" class="space-y-4">
        <div>
            <h2 class="font-semibold">Monthly minimum</h2>
            <p class="text-sm text-muted-foreground">
                Ask every member to drive a minimum each month (UTC), counted
                from the jobs they sync while in the company. Leave a box empty
                for no minimum. Someone who joins mid-month owes only their
                share of it. Owners and managers see everyone's progress on the
                members list and get one notification on the 1st naming who
                missed last month. Nobody is removed automatically.
            </p>
        </div>
        <Form
            v-bind="update.form(slug)"
            class="space-y-3"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="activity_min_km">Distance per month (km)</Label>
                    <Input
                        id="activity_min_km"
                        name="min_km"
                        type="number"
                        min="1"
                        max="100000"
                        inputmode="numeric"
                        :default-value="activity.min_km ?? ''"
                    />
                    <InputError :message="errors.min_km" />
                </div>
                <div class="grid gap-2">
                    <Label for="activity_min_deliveries">Jobs per month</Label>
                    <Input
                        id="activity_min_deliveries"
                        name="min_deliveries"
                        type="number"
                        min="1"
                        max="1000"
                        inputmode="numeric"
                        :default-value="activity.min_deliveries ?? ''"
                    />
                    <InputError :message="errors.min_deliveries" />
                </div>
            </div>
            <Button :disabled="processing">Save monthly minimum</Button>
        </Form>
    </section>
</template>
