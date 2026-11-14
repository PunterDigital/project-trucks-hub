<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import { store } from '@/routes/reports';

// "Report" on profiles, companies and jobs (#4651). Staff see it in the admin panel's queue.
const props = defineProps<{
    type: 'driver' | 'company' | 'job';
    id: number;
    /** What's being reported, for the dialog title ("this driver"). */
    what: string;
}>();

const reasons = [
    { value: 'offensive', label: 'Offensive name or text' },
    { value: 'spam', label: 'Spam or advertising' },
    { value: 'cheating', label: 'Cheated or fake jobs' },
    { value: 'impersonation', label: 'Pretending to be someone else' },
    { value: 'other', label: 'Something else' },
] as const;

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                class="text-muted-foreground"
                data-test="report-button"
            >
                <Flag class="size-4" /> Report
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="store.form()"
                :options="{ preserveScroll: true }"
                class="space-y-5"
                reset-on-success
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader class="space-y-2">
                    <DialogTitle>Report {{ props.what }}</DialogTitle>
                    <DialogDescription>
                        Only Hub staff see reports. They don't tell anyone who
                        sent one.
                    </DialogDescription>
                </DialogHeader>

                <input type="hidden" name="type" :value="props.type" />
                <input type="hidden" name="id" :value="props.id" />

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        What's wrong?
                    </legend>
                    <label
                        v-for="reason in reasons"
                        :key="reason.value"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="radio"
                            name="reason"
                            :value="reason.value"
                            required
                            class="size-4 accent-primary"
                        />
                        {{ reason.label }}
                    </label>
                    <InputError :message="errors.reason" />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="report-details">Details (optional)</Label>
                    <textarea
                        id="report-details"
                        name="details"
                        rows="3"
                        maxlength="1000"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="Anything that helps staff see the problem"
                    />
                    <InputError :message="errors.details" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary"
                            >Cancel</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-test="report-submit"
                        >Send report</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
