export type Level = 'error' | 'warning' | 'info';
export type Risk = 'safe' | 'moderate' | 'invasive';

export interface Alert {
	level: Level;
	code: string;
	message: string;
}

export interface Module {
	id: string;
	label: string;
	description: string;
	risk: Risk;
	riskLabel: string;
	defaultEnabled: boolean;
	phase: number;
	available: boolean;
	enabled: boolean;
	requested: boolean;
	forcedOff: string[];
}

export interface SafeModeDetails {
	reason: string;
	at: number;
	notified: boolean;
	context?: { message?: string; file?: string; line?: number; count?: number };
}

export interface Safety {
	kill_switch: { active: boolean; reason: string | null; constant: boolean; file: boolean };
	bypass_cookie: { available: boolean; active: boolean; expires: number | null };
	safe_mode: { active: boolean; details: SafeModeDetails | null };
	data_dir: { path: string; exists: boolean; writable: boolean };
}

export interface Conflict {
	slug: string;
	plugin_file: string;
	category: string;
	modules: string[];
}

export interface Status {
	version: string;
	environment: {
		server: { software: string; family: string };
		php: string;
		wordpress: string;
		multisite: boolean;
		cache_plugin: string | null;
		woocommerce: { active: boolean; version: string | null };
		multilingual: string | null;
		cloudflare: { proxied_request: boolean; official_plugin: boolean };
		object_cache: boolean;
		opcache: boolean;
		wp_cron: { disabled: boolean };
		theme: { stylesheet: string; template: string };
	};
	safety: Safety;
	loader: { installed: boolean; current: boolean; writable: boolean; path: string; ran_this_request: boolean };
	config: { exists: boolean; hash: string; generated: number; version: string; file: string };
	modules: Module[];
	overlap: { conflicts: Conflict[]; coordinated: string[]; multiple_page_caches: boolean };
	license: { protected: boolean; licensed: boolean | null; client_active: boolean; license_page: string; masked_key: string };
	matrix: { version: string; error: string };
	alerts: Alert[];
}

export interface CompatReport {
	matrix: { version: string; generated: string; error: string; signed: boolean };
	integrations: { id: string; title: string; type: string; notes: string }[];
}

export interface EventItem {
	id: number;
	created_at: string;
	module: string;
	type: string;
	severity: 0 | 1 | 2;
	message: string;
	context: Record<string, unknown> | null;
}

export interface EventsPage {
	items: EventItem[];
	total: number;
	page: number;
	per_page: number;
	modules: string[];
}

export interface PublicSettings {
	debug: boolean;
	staging_domains: string[];
	modules: Record<string, boolean>;
}

export interface CompileResult {
	ok: boolean;
	hash: string;
	file: string;
	errors: string[];
	warnings: string[];
}
