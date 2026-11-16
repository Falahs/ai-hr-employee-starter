<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{
    status?: string;
    /** no agent mailbox: the link cannot be delivered safely, so the page sends the person to the admin */
    mailbox: boolean;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout :title="$t('ui.auth.forgot_page.title')" :description="$t('ui.auth.forgot_page.description')">
        <Head :title="$t('ui.auth.forgot_page.title')" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div class="space-y-6">
            <p v-if="!mailbox" class="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">{{ $t('ui.auth.forgot_page.no_mailbox') }}</p>
            <form v-else @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="email">{{ $t('ui.auth.email') }}</Label>
                    <Input id="email" type="email" name="email" autocomplete="off" v-model="form.email" autofocus placeholder="name@company.com" dir="ltr" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button class="w-full" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ $t('ui.auth.forgot_page.submit') }}
                    </Button>
                </div>
            </form>

            <div class="flex justify-center gap-1 text-sm text-muted-foreground">
                <span>{{ $t('ui.auth.forgot_page.back') }}</span>
                <TextLink :href="route('login')">{{ $t('ui.auth.login.head') }}</TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
