<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconClock } from '@tabler/icons-vue';

import TextLink from '@/components/TextLink.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
</script>

<template>
    <AuthLayout
        :title="$t('auth.pending_approval.title')"
        :description="$t('auth.pending_approval.description')"
    >
        <Head :title="$t('auth.pending_approval.page_title')" />

        <div class="space-y-6 text-center">
            <IconClock class="mx-auto h-10 w-10 text-muted-foreground" />

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                {{ $t('auth.pending_approval.log_out') }}
            </TextLink>
        </div>
    </AuthLayout>
</template>
