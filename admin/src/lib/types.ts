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

export interface SignedCookie {
	available: boolean;
	active: boolean;
	expires: number | null;
}

export interface Safety {
	kill_switch: { active: boolean; reason: string | null; constant: boolean; file: boolean };
	bypass_cookie: SignedCookie;
	diag_cookie: SignedCookie;
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

export type Period = '1h' | '24h' | '48h' | '7d' | '30d';

export interface Sampling {
	enabled: boolean;
	rate: number;
	intensive_until: number;
	intensive_seconds: number;
}

export interface PluginCost {
	component: string;
	load_ms: number;
	hook_ms: number;
	total_ms: number;
	kb: number;
	share_pct: number;
	seen_pct: number;
}

export interface PhaseCost {
	phase: string;
	ms: number;
	seen_pct: number;
}

export interface ProfilerSummary {
	period: Period;
	sample_hours: number;
	sampling: Sampling;
	sources: { random: number; intensive: number; diag: number };
	summary: {
		samples: number;
		ms_avg: number;
		ms_p50: number;
		ms_p95: number;
		mem_avg_mb: number;
		queries_avg: number;
		overhead_us_avg: number;
		cacheable_pct: number;
		via_cf_pct: number;
		by_type: Record<string, number>;
	};
	phases: PhaseCost[];
	passive: { cookies: Record<string, number>; private_cache: number; eligible: number };
	latest_diag: null | {
		created_at: string;
		request_type: string;
		route_key: string;
		ms: number;
		mem_kb: number;
		queries: number;
		overhead_us: number;
		phases: Record<string, number> | null;
		plugins: PluginCost[];
	};
	rollup: { last_hour: string | null };
}

export interface RouteCost {
	request_type: string;
	route_key: string;
	n: number;
	ms_p50: number;
	ms_p95: number;
	mem_p95: number;
	queries_avg: number;
}

export interface AuditResult {
	label: string;
	url: string;
	status: number;
	verdict: 'ok' | 'warning' | 'error';
	issues: string[];
	cookies: { name: string; hint: string }[];
	headers: Record<string, string>;
}

export interface AuditReport {
	at: number;
	results: AuditResult[];
	issues: number;
}

export interface HubStatus {
	configured: boolean;
	url: string;
	registered: boolean;
	site_id: string;
	registered_at: number;
	last_ok_at: number;
	last_error: string;
	failures: number;
	next_attempt: number;
	pending_hours: number;
	license: boolean;
	result?: { sent: boolean; reason: string };
}

export interface CacheSettings {
	mode: 'sync' | 'headers';
	edge_ttl: number;
	swr: number;
	sie: number;
	nonce_refresh: boolean;
	warm: boolean;
}

export interface CacheStatus {
	enabled: boolean;
	settings: CacheSettings;
	adapter: { id: 'litespeed' | 'wp-rocket' | 'none'; server: string; headers_on_hit: boolean; edge_html: boolean; verified: boolean };
	edge: { available: boolean; supports_tags: boolean };
	tags: { prefix: string };
	queue: { tags: number; urls: number; all: boolean; warm: number };
	last: { at?: number; tags?: number; urls?: number; all?: boolean; edge?: string; warm?: number };
	warmer: { last_run: number; warmed: number; errors: number; avg_ms: number; paused_at: number; load: number; seeded_at: number };
	sitemaps: string[];
	result?: Record<string, unknown>;
}

export interface InspectResult {
	url: string;
	status: number;
	headers: Record<string, string>;
	cookies: string[];
}
