import { defineStore } from 'pinia'
import { ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export interface LicenseStatus {
	status: string
	statusKind: string
	dateExpiration: string
	email: string
	level: string
	licensekey: string
	product: string
	dateLastCheck: string
	istrial: number
}

export const useLicenseStore = defineStore('license', () => {
	const status = ref<LicenseStatus | null>(null)
	const email = ref('')
	const licenseKey = ref('')
	const loading = ref(false)

	/**
	 *
	 */
	async function refreshStatus() {
		loading.value = true
		try {
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/licensestatusinternal')
			const { data } = await axios.get<LicenseStatus>(url)
			status.value = data
			email.value = data.email || ''
			licenseKey.value = data.licensekey || ''
		} catch (err) {
			console.warn('Error fetching license status', err)
			status.value = null
		} finally {
			loading.value = false
		}
	}

	/**
	 *
	 */
	async function activateLicense() {
		loading.value = true
		try {
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/license')
			await axios.post(url, { email: email.value, license: licenseKey.value })
		} catch (err) {
			console.error('Could not activate license', err)
		}
		await refreshStatus()
	}

	/**
	 *
	 */
	async function clearLicense() {
		email.value = ''
		licenseKey.value = ''
		loading.value = true
		try {
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/license')
			await axios.post(url, { email: '', license: '' })
		} catch (err) {
			console.error('Could not clear license', err)
		}
		await refreshStatus()
	}

	return {
		status,
		email,
		licenseKey,
		loading,
		refreshStatus,
		activateLicense,
		clearLicense,
	}
})
