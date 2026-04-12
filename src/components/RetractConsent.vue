<template>
	<div class="retract-consent">
		<h3>{{ t('sendentsynchroniser', 'Retract consent') }}</h3>
		<p>{{ t('sendentsynchroniser', 'Click on the button below to retract permission to access your Exchange mailbox.') }}</p>
		<div class="retract-consent__actions">
			<button class="retract-consent__btn" @click="retractConsent">
				{{ t('sendentsynchroniser', 'Retract consent') }}
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

async function retractConsent() {
	const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/invalidate')
	await axios.get(url)
	location.reload()
}
</script>

<style scoped>
.retract-consent {
	margin-top: 24px;
}

.retract-consent h3 {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 8px;
}

.retract-consent p {
	margin-bottom: 12px;
}

.retract-consent__btn {
	color: var(--color-error-text);
	border-color: var(--color-error-text);
}
</style>
