export interface AdminConfig {
	restUrl: string;
	nonce: string;
	version: string;
	adminUrl: string;
	locale: string;
	userId: number;
	killed: boolean;
	safeMode: boolean;
	pluginUrl: string;
}

declare global {
	interface Window {
		itbAdmin?: AdminConfig;
		wp?: { i18n?: { __: ( text: string, domain: string ) => string } };
	}
}

const fallback: AdminConfig = {
	restUrl: '/wp-json/imagina-turbo/v1/',
	nonce: '',
	version: '0.0.0',
	adminUrl: '/wp-admin/',
	locale: 'es',
	userId: 0,
	killed: false,
	safeMode: false,
	pluginUrl: '',
};

export const config: AdminConfig = { ...fallback, ...( window.itbAdmin ?? {} ) };
