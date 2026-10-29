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
	ai_write: boolean;
	abilities_api: boolean;
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
		db_queries: null | {
			at: number;
			count: number;
			ms: number;
			slow: { sql: string; ms: number; component: string }[];
			repeated: { sql: string; count: number; ms: number; component: string }[];
			components: { component: string; count: number; ms: number }[];
		};
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

export interface PerVisitItem {
	request_type: string;
	route_key: string;
	samples: number;
	per_day: number;
	ms_avg: number;
	cpu_s_day: number;
	per_view: number | null;
	per_visit: boolean;
	rule: string;
	hint: string;
}

export interface PerVisitReport {
	hours: number;
	rate: number;
	views_per_day: number | null;
	rum_beacons: number;
	items: PerVisitItem[];
}

export interface PlatformCheck {
	id: string;
	label: string;
	status: 'ok' | 'warn' | 'info';
	advice: string;
}

export interface PlatformReport {
	elementor: PlatformCheck[] | null;
	woodmart: PlatformCheck[] | null;
	woocommerce: PlatformCheck[] | null;
	server: PlatformCheck[];
}

export interface ImageItem {
	id: number;
	file: string;
	width: number;
	height: number;
	bytes: number;
}

export interface ImageScan {
	started_at: number;
	finished_at: number;
	cursor: number;
	done: boolean;
	total: number;
	mime: Record<string, number>;
	bytes_main: number;
	bytes_sizes: number;
	convertible: number;
	webp: number;
	avif: number;
	missing: number;
	no_meta: number;
	large_n: number;
	heavy_n: number;
	large: ImageItem[];
	heavy: ImageItem[];
}

export interface EwwwState {
	version: string;
	jpg_level: number;
	png_level: number;
	webp: boolean;
	webp_quality: number;
	webp_effective: number;
	sharpen: boolean;
	naming: 'append' | 'replace';
	max_width: number;
	max_height: number;
	metadata_remove: boolean;
	backup: boolean;
	cloud: boolean;
	queue: number | null;
	pending: number | null;
	bulk_resume: string;
	optimized: number | null;
	saved_bytes: number | null;
}

export interface ImageAuditReport {
	scan: ImageScan | null;
	ewww: EwwwState | null;
	optimizers: string[];
	recommendations: { id: string; level: 'warn' | 'info'; text: string }[];
	links: { ewww_settings: string; ewww_bulk: string; media: string };
}

export interface WorkersReport {
	hours: number;
	rate: number;
	children: number | null;
	avg: number;
	peak: number;
	peak_hour: string;
	top: { request_type: string; route_key: string; share_pct: number; ms_p95: number; slow: number }[];
}

export interface AuditResult {
	label: string;
	url: string;
	status: number;
	verdict: 'ok' | 'info' | 'warning' | 'error';
	issues: string[];
	notes?: string[];
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
	fragments: boolean;
	edge_variants: boolean;
}

export interface FragmentPageResult {
	path: string;
	status: number;
	hits: number;
	stores: number;
	result: 'ok' | 'no_menus' | 'partial' | 'different' | 'no_stats' | 'error';
	diff: string;
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
	fragments: { available: boolean; ajax: string[]; verify: { at: number; pass: boolean; pages: FragmentPageResult[]; error: string } | null };
	vary_cookies: string[];
	result?: Record<string, unknown>;
}

export interface InspectResult {
	url: string;
	status: number;
	headers: Record<string, string>;
	cookies: string[];
}

export type CfAction = 'create' | 'update' | 'delete' | 'none' | 'drifted' | 'blocked';

export interface CfStatus {
	available: boolean;
	enabled: boolean;
	token: { state: 'none' | 'ok' | 'rotated' | 'broken'; last4: string; saved: number };
	zone: { id: string; name: string; plan: string; plan_name: string; status: string } | null;
	permissions: Record<string, boolean>;
	guard: { blocked: boolean; reason: string };
	simulator: boolean;
	checked_at: number;
	error: string;
	token_url: string;
	token_permissions: string[];
	footprint: { rules: number; settings: number };
}

export interface CfSetting {
	id: string;
	label: string;
	value: unknown;
	readable: boolean;
	recommended: string | number;
	default: string | number;
	options: ( string | number )[];
	managed: boolean;
	risk: 'safe' | 'moderate' | 'invasive';
	read_only: boolean;
	note: string;
	error: string;
}

export interface CfZone {
	settings: CfSetting[];
	alerts: { level: 'error' | 'warning' | 'info'; message: string }[];
	phases: { phase: string; label: string; own: number; foreign: number; limit: number }[];
	smart_shield: Record<string, { value: string; editable: boolean }> | null;
	dashboard: { crawler_hints: string; ai_crawl: string };
}

export interface CfChange {
	id: string;
	kind: 'rule' | 'setting';
	title: string;
	description: string;
	action: CfAction;
	risk: 'safe' | 'moderate' | 'invasive';
	reason: string;
	current: unknown;
	desired: unknown;
}

export interface CfPlan {
	changes: CfChange[];
	plan: string;
	notes: string[];
}

export interface CfApplyResult {
	applied: string[];
	failed: { id: string; error: string }[];
	snapshot_id: number;
	rolled_back: boolean;
}

export interface CfSnapshot {
	id: number;
	created_at: string;
	reason: string;
}

export interface CfAnalytics {
	available: boolean;
	error: string;
	fetched_at: number;
	window_hours: number;
	requests: number;
	cached: number;
	ratio: number | null;
	by_status: { status: string; requests: number }[];
	top_uncached: { path: string; requests: number }[];
}

export interface WooStatus {
	woocommerce: boolean;
	enabled: boolean;
	theme: { slug: string; known: boolean; verified: boolean };
	selectors: { count: string[]; total: string[]; menu: string[] };
	custom: { count: string[]; total: string[] };
	recently_viewed: boolean;
	geolocation: boolean;
	tax_incl: boolean;
	known_themes: string[];
}

export interface WooInspectCase {
	error?: string;
	status?: number;
	edge?: string;
	script?: boolean;
	fragments?: boolean;
	set_cookies?: string[];
}

export interface WooInspect {
	empty: WooInspectCase;
	cart: WooInspectCase;
}

export interface ShieldStatus {
	enabled: boolean;
	settings: { static: boolean; author: boolean; xmlrpc: boolean; rest_users: boolean; heartbeat: boolean; prefetch: boolean; facets: boolean; facets_views: boolean; facets_robots: boolean; facets_empty404: boolean; facets_scope: 'combos' | 'all'; heartbeat_admin: number; heartbeat_editor: number; filter_limit: number };
	required: { xmlrpc: boolean; rest_users: boolean };
	robots: { url: string; physical: boolean; rules: string[] };
	cron: { disabled: boolean; command: string; wp_cli: string };
}

export interface ShieldTestCase {
	error?: string;
	status?: number;
	shield?: string;
	ms?: number;
	url?: string;
}

export interface Tweak {
	id: string;
	label: string;
	description: string;
	risk: 'safe' | 'moderate' | 'invasive';
	default: boolean;
	enabled: boolean;
}

export interface TweaksStatus {
	enabled: boolean;
	forced_off: string;
	tweaks: Tweak[];
	revisions: number;
	autosave: number;
	perfmatters: boolean;
	imported?: number;
}

export interface PerfmattersReport {
	found: boolean;
	active: boolean;
	mapped: { from: string; to: string; value: string }[];
	unmapped: { from: string; where: string }[];
}

export interface FirewallEvents {
	available: boolean;
	error: string;
	fetched_at?: number;
	total: number;
	events: { rule: string; action: string; count: number; top_path: string; top_country: string }[];
}

export interface HtaccessResult {
	ok: boolean;
	error: string;
	checks: { url: string; status: number }[];
	restored: boolean;
}

export interface FirewallStatus {
	enabled: boolean;
	settings: { rules: { exploits: boolean; surface: boolean; bots: boolean; facets: boolean }; ratelimit: boolean };
	cloudflare: { connected: boolean; module_enabled: boolean; plan: string; waf_permission: boolean | null };
	changes: CfChange[];
	notes: string[];
	error: string;
	events: FirewallEvents;
	origin: { samples: number; cloudflare: number; direct: number; local: number; proxy_ip: number; state: 'unknown' | 'no_cloudflare' | 'open' | 'closed'; message: string; real_ip_missing: boolean; ranges_url: string };
	htaccess: { server: string; applicable: boolean; ols: boolean; block: string; exists: boolean; writable: boolean; active: boolean; backup_at: number };
}

export interface FilterProbe {
	method: 'GET' | 'POST';
	path: string;
	body: string;
}

export interface FilterSide {
	n: number;
	ms: number;
	kb: number;
	errors: number;
}

export interface FilterRule {
	id: string;
	key: string;
	label: string;
	plugins: string[];
	all_methods: boolean;
	probes: FilterProbe[];
	status: 'testing' | 'active' | 'failed' | 'disabled';
	reason: string;
	created_at: number;
	updated_at: number;
	checked_at: number;
	inactive: string[];
	protected: string[];
	evidence: {
		pairs: number;
		required: number;
		modes: Record<string, 'exact' | 'structural'>;
		baseline: FilterSide;
		filtered: FilterSide;
		hidden: number;
		mismatch: { probe: number; field: string; baseline: string; filtered: string } | null;
	};
}

export interface FilterCandidate {
	key: string;
	label: string;
	samples: number;
	ms_avg: number;
	mem_avg_mb: number;
	plugins: { plugin: string; total_ms: number; kb: number }[];
	saving_ms: number;
	blocked: string;
}

export interface FilterStatus {
	enabled: boolean;
	forced_off: string;
	plugins: { file: string; name: string }[];
	self: string;
	rules: FilterRule[];
	candidates: FilterCandidate[];
	imagina_search: { active: boolean; key: string };
	faults_pending: boolean;
	required_pairs: number;
}

export interface RumVitals {
	template: string;
	device: 'm' | 'd';
	n: number;
	lcp: number | null;
	inp: number | null;
	cls: number | null;
	ttfb: number | null;
	req?: number | null;
	js?: number | null;
	css?: number | null;
	kb?: number | null;
	prerendered: number;
	prefetched: number;
	bfcache: number;
	back_forward: number;
}

export interface RumEventRow {
	template: string;
	device: string;
	value: string;
	n: number | string;
	bits: string;
}

export interface RumTtfb {
	device: 'm' | 'd';
	n: number;
	ttfb: number | null;
	conn: number | null;
	wait_cached: number | null;
	wait_generated: number | null;
	cached_pct: number | null;
	cached_n: number;
}

export interface RumReportData {
	days: number;
	navigation: { navigations: number; speculated_pct: number | null; prerendered: number; prefetched: number; bfcache_pct: number | null; bfcache: number; back_forward: number; reloads: number };
	vitals: RumVitals[];
	site?: RumVitals[];
	ttfb: RumTtfb[];
	bfcache: { reason: string; n: number }[];
	next: RumEventRow[];
	lcp: RumEventRow[];
}

export interface RumInsightsData {
	predicted: Record<string, string[]>;
	lcp: Record<string, { kind: 'image' | 'featured'; id: number; src: string; n: number; share: number }>;
	fonts: Record<string, string[]>;
	generated: number;
}

export interface NavigationSettings {
	spec_mode: 'prefetch' | 'prerender';
	spec_eagerness: 'conservative' | 'moderate' | 'eager';
	rum_rate: number;
	spec_predictive: boolean;
	spec_fallback: boolean;
	view_transitions: boolean;
	hints_lcp: boolean;
	hints_fonts: boolean;
	fonts_manual: string[];
	hints_facades: boolean;
	lazy_render: LazyRenderRule[];
	lazy_elements: boolean;
	lazy_documents: LazyDocument[];
}

export interface LazyDocument {
	id: number;
	mode: 'v' | 'i';
	height: number;
}

export interface LazyDocumentDetected {
	id: number;
	title: string;
	type: string;
	bytes: number;
	tags: number;
	imgs: number;
	where: 'menu' | 'header' | 'footer' | 'content';
	pages: number;
	suggest: '' | 'v' | 'i';
}

export interface LazyRenderRule {
	selector: string;
	templates: string[];
	height: number;
}

export interface NavigationStatus {
	modules: Record<'speculation' | 'rum' | 'frontend_hints', { enabled: boolean; forced_off: string }>;
	settings: NavigationSettings;
	core_api: boolean;
	lazy_presets: LazyRenderRule[];
	theme: string;
	prefetch_probe: '' | 'refused' | 'ok' | 'direct';
	permalink: boolean;
	hub_rum: boolean;
	report: RumReportData;
	insights: RumInsightsData;
	no_store: { route: string; n: number }[];
	elementor: boolean;
	lazy_detected: { docs: LazyDocumentDetected[]; errors: string[]; generated_at: number } | null;
	budgets: { values: Budgets; defaults: Budgets; state: BudgetState };
}

export type BudgetMetric = 'lcp' | 'inp' | 'cls' | 'ttfb' | 'req' | 'js' | 'css' | 'kb';
export type Budgets = Record<BudgetMetric, number>;

export interface BudgetBreach {
	template: string;
	device: 'm' | 'd';
	metric: BudgetMetric;
	value: number;
	budget: number;
}

export interface BudgetState {
	checked_at: number;
	budgets: Budgets;
	site: RumVitals[];
	breaches: BudgetBreach[];
}

export interface AssetRule {
	id: string;
	handle: string;
	type: 'js' | 'css';
	mode: 'never' | 'only';
	templates: string[];
	ids: number[];
	post_types: string[];
	status: 'testing' | 'active' | 'disabled';
	note: string;
	verified_by: number;
	verified_at: number;
	created_at: number;
}

export interface InventoryAsset {
	handle: string;
	src: string;
	origin: 'plugin' | 'theme' | 'core' | 'external' | 'inline' | 'other';
	owner: string;
	size: number;
	deps: string[];
	footer: boolean;
	enqueued: boolean;
	needed_by: string[];
	protected: boolean;
	rules: { id: string; status: AssetRule[ 'status' ] }[];
}

export interface AssetInventory {
	path: string;
	status: number;
	context: { template: string; id: number; post_type: string };
	js: InventoryAsset[];
	css: InventoryAsset[];
	shortcodes: string[];
	blocks: string[];
}

export interface AssetSuggestion {
	plugin: string;
	label: string;
	kind: 'form' | 'slider' | 'woo';
	risk: 'moderate';
	handle: string;
	type: 'js' | 'css';
	mode: 'only';
	templates: string[];
	ids: number[];
	note: string;
}

export interface LateSheet {
	h: string;
	src: string;
	ver: string;
	media: string;
}

export interface AssetsStatus {
	enabled: boolean;
	forced_off: string;
	rules: AssetRule[];
	preview: boolean;
	suggestions: {
		rules: AssetSuggestion[];
		unused: { plugin: string; label: string; kind: string }[];
		scanned: { path: string; template: string }[];
		errors: string[];
		generated_at: number;
	} | null;
	templates: string[];
	post_types: string[];
	home: string;
	late_styles: {
		active: Record<string, LateSheet[]>;
		detected: {
			templates: Record<string, { paths: string[]; sheets: ( LateSheet & { size: number } )[] }>;
			errors: string[];
			generated_at: number;
		} | null;
	};
	scripts: ScriptLoadingStatus;
}

export interface ScriptLoadingStatus {
	enabled: boolean;
	forced_off: string;
	defer: boolean;
	defer_exclude: string[];
	delay: string[];
	delay_timeout: number;
	delay_scope: 'compat' | 'essential';
	lsdelay_timeout: number;
	cache_defers: boolean;
	cache_delays: boolean;
}

export interface DbAudit {
	generated_at: number;
	autoload: { count: number; bytes: number; top: { name: string; bytes: number; owner: 'core' | 'active' | 'inactive' | 'unknown'; plugin: string }[] };
	inactive_options: { count: number; bytes: number; autoloaded: number; items: { name: string; bytes: number; autoload: boolean; plugin: string }[] };
	transients: { expired: number; object_cache: boolean };
	action_scheduler: { available: boolean; old: number; complete: number; failed: number; canceled: number; pending: number; total: number; logs: number };
	revisions: { total: number; excess: number; posts: number; keep: number; wp_limit: number };
	orphan_postmeta: number;
	hpos: { woo: boolean; enabled: boolean; sync: boolean; posts_orders: number; recommendation: string; incompatible?: string[] };
	postmeta_index: { rows: number; indexes: string[]; recommendation: string };
	tables: { name: string; rows: number; bytes: number; owner?: 'core' | 'active' | 'inactive' | 'removed' | 'unknown'; plugin?: string }[];
}

export interface DbStatus {
	enabled: boolean;
	audit: DbAudit | null;
	batches: { batch: string; created_at: string; action: string; rows: number; restored: boolean }[];
	keep: number;
	settings: { db_keep_revisions: number; db_schedule: string[]; db_schedule_days: number; db_as_retention: number };
	last_run: number;
	result?: { action: string; batch: string; affected: number; backed_up: number; more: boolean };
	restored?: { batch: string; restored: number; skipped: number };
}

export interface FleetDrift {
	kind: 'setting' | 'module' | 'tweak' | 'cloudflare';
	key: string;
	label: string;
	current: unknown;
	desired: unknown;
	risk: 'safe' | 'moderate' | 'invasive';
}

export interface FleetPolicy {
	id: string;
	version: number;
	name: string;
	settings: Record<string, unknown>;
	modules: Record<string, boolean>;
	tweaks: Record<string, boolean>;
	cloudflare: { rules: string[]; settings: string[] };
	ignored: string[];
}

export interface FleetStatus {
	hub: { configured: boolean; registered: boolean };
	state: {
		policy: FleetPolicy | null;
		fetched_at: number;
		error: string;
		drift: FleetDrift[];
		cloudflare_error: string;
		checked_at: number;
		applied: { id: string; version: number; at: number; source: string } | null;
	};
	remote: boolean;
	snapshots: { id: string; created_at: number; source: string; policy: string }[];
	endpoint: string;
	result?: { applied: string[]; skipped: { key: string; reason: string }[]; cloudflare: Record<string, unknown> | null; snapshot: string };
}

export interface ZarazTool {
	id: string;
	name: string;
	component: string;
	enabled: boolean;
	own: boolean;
	id_value: string;
}

export interface ZarazStatus {
	ready: boolean;
	cloudflare_module: boolean;
	plugins: {
		ga4: { id: string; source: string; takeover: boolean }[];
		gtm: { id: string; source: string }[];
		meta: { id: string; source: string; takeover: boolean }[];
	};
	page: { at: number; ids: { ga4: string[]; google_tag: string[]; gtm: string[]; meta: string[] } } | null;
	zone: { ok: boolean; error: string; workflow: string; secrets: boolean; tools?: ZarazTool[]; ecommerce?: boolean; auto_inject?: boolean } | null;
	takeover: { ga4: boolean; meta: boolean };
	state: { applied_at?: number; ga4?: string; meta?: string; published?: boolean };
	result?: { ok: boolean; error: string; published?: boolean };
}

export interface VerifierRunBrief {
	id: string;
	trigger: 'manual' | 'nightly';
	status: 'queued' | 'running' | 'done' | 'error';
	created_at: number;
	finished_at: number | null;
	pass: boolean | null;
	alerts: string[];
	pages: { label: string; pass: boolean; status: number; lcp: number | null; cls: number | null; score: number | null; failures: number; warnings: number }[];
}

export interface VerifierPageResult {
	label: string;
	url: string;
	status: number;
	pass: boolean;
	ttfb: number | null;
	lcp: number | null;
	cls: number | null;
	cache: Record<string, string>;
	failures: string[];
	warnings: string[];
	visual?: number;
	baseline_created?: boolean;
	flow?: { pass: boolean; reason?: string; items?: number; cleaned?: boolean };
	lighthouse?: { score: number | null; metrics: Record<string, number | null>; opportunities: { id: string; title: string; savings_ms: number }[]; error?: string };
}

export interface VerifierRun {
	id: string;
	trigger: 'manual' | 'nightly';
	status: 'queued' | 'running' | 'done' | 'error';
	created_at: number;
	finished_at?: number;
	options: { lighthouse: boolean; visual: boolean };
	results: VerifierPageResult[];
	pass: boolean | null;
	alerts: string[];
	error?: string;
}

export interface VerifierStatus {
	url: string;
	site: string;
	key: { state: string; last4: string; saved: number };
	configured: boolean;
	pages: { label: string; url: string; flow?: string; expect?: number }[];
	site_url: string;
	runs: VerifierRunBrief[] | null;
	error: string;
	sites_json: Record<string, unknown>;
	new_key?: string;
}


export type TurnstileForm = 'login' | 'register' | 'lostpassword' | 'checkout';

export interface TurnstileStatus {
	enabled: boolean;
	active: boolean;
	site_key: string;
	secret: { state: 'none' | 'ok' | 'rotated' | 'broken'; last4: string };
	verified: boolean;
	forms: Record<TurnstileForm, boolean>;
	skip: string[];
	gateways: { id: string; title: string }[];
	woo: boolean;
	conflicts: string[];
	api_js: string;
	dashboard: string;
	test?: { ok: boolean; codes: string[] };
}
