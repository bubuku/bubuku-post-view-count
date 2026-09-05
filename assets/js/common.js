// Known AI assistants — single source of truth for both the generic 'ai'
// referrer bucket (getReferrerClass) and the specific assistant behind it
// (getAiAssistantClass), so the two can never drift apart.
const BK_AI_ASSISTANTS = [
	{ slug: 'chatgpt', domains: ['chatgpt.com', 'chat.openai.com'], attribution: ['chatgpt', 'chatgpt.com', 'openai'] },
	{ slug: 'claude', domains: ['claude.ai'], attribution: ['claude', 'claude.ai'] },
	{ slug: 'perplexity', domains: ['perplexity.ai'], attribution: ['perplexity', 'perplexity.ai'] },
	{ slug: 'copilot', domains: ['copilot.microsoft.com'], attribution: ['copilot', 'copilot.microsoft.com'] },
	{ slug: 'gemini', domains: ['gemini.google.com'], attribution: ['gemini', 'gemini.google.com'] },
];

const bk_matchesDomain = (host, domain) => host === domain || host.endsWith(`.${domain}`);

const bk_postview_main = {
	time_delay: 5000,
	end_point: null,
	post_id: null,
	elapsed_visible: 0,
	visible_since: null,
	timer_id: null,
	sent: false,
	state: 'not-eligible',
	init: function () {
		this.end_point = bbk_post_view.api_public;
		this.post_id = bbk_post_view.post_id;
		document.addEventListener('visibilitychange', () => this.updateVisibility());
		window.addEventListener('pagehide', () => this.pauseVisibleTimer());
		window.addEventListener('pageshow', () => this.updateVisibility());
		window.addEventListener('online', () => {
			if (this.state === 'pending' && this.elapsed_visible >= this.time_delay) this.setPostView();
		});
		this.updateVisibility();
	},
	now: function () {
		return window.performance && typeof window.performance.now === 'function'
			? window.performance.now()
			: Date.now();
	},
	updateVisibility: function () {
		if (this.sent) return;

		if (document.visibilityState === 'visible') {
			this.state = 'pending';
			if (this.visible_since === null) this.visible_since = this.now();
			this.scheduleRemainingTime();
			return;
		}

		this.pauseVisibleTimer();
	},
	pauseVisibleTimer: function () {
		if (this.visible_since !== null) {
			this.elapsed_visible += Math.max(0, this.now() - this.visible_since);
			this.visible_since = null;
		}

		if (this.timer_id !== null) {
			clearTimeout(this.timer_id);
			this.timer_id = null;
		}
	},
	scheduleRemainingTime: function () {
		if (this.timer_id !== null || this.sent) return;

		const remaining = Math.max(0, this.time_delay - this.elapsed_visible);
		this.timer_id = setTimeout(() => {
			this.timer_id = null;
			this.pauseVisibleTimer();
			if (this.elapsed_visible >= this.time_delay) this.setPostView();
			else this.updateVisibility();
		}, remaining);
	},
	// Bucketed device width — never the exact pixel width (fingerprinting vector).
	getViewportBucket: function () {
		const w = window.innerWidth;
		if (w < 576) return '<576';
		if (w < 992) return '576-991';
		if (w < 1400) return '992-1399';
		return '>=1400';
	},
	// Classified referrer — never the raw host or full URL, only one of a fixed set of buckets.
	getReferrerClass: function () {
		const AI_DOMAINS = BK_AI_ASSISTANTS.flatMap((assistant) => assistant.domains);
		const AI_ATTRIBUTION_VALUES = BK_AI_ASSISTANTS.flatMap((assistant) => assistant.attribution);
		const SEARCH_DOMAINS = ['bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com'];
		const SOCIAL_DOMAINS = [
			'facebook.com',
			'twitter.com',
			'x.com',
			't.co',
			'linkedin.com',
			'instagram.com',
			'reddit.com',
		];
		const matchesAnyDomain = (host, domains) => domains.some((domain) => bk_matchesDomain(host, domain));
		const attribution = new URLSearchParams(location.search);
		const attributedSource = (attribution.get('utm_source') || attribution.get('ref') || '').toLowerCase().trim();

		if (AI_ATTRIBUTION_VALUES.includes(attributedSource)) return 'ai';

		const ref = document.referrer;
		if (!ref) return 'direct';

		let host;
		try {
			host = new URL(ref).hostname.toLowerCase().replace(/\.$/, '');
		} catch (e) {
			return 'other';
		}

		const currentHost = location.hostname.toLowerCase().replace(/\.$/, '');

		if (host === currentHost) return 'internal';

		if (matchesAnyDomain(host, AI_DOMAINS)) return 'ai';
		if (matchesAnyDomain(host, SEARCH_DOMAINS) || /(^|\.)google\.[a-z.]+$/.test(host) || /(^|\.)yandex\.[a-z.]+$/.test(host)) return 'search';
		if (matchesAnyDomain(host, SOCIAL_DOMAINS) || /(^|\.)pinterest\.[a-z.]+$/.test(host)) return 'social';
		return 'other';
	},
	// Specific AI assistant behind a getReferrerClass() result of 'ai' — resolved
	// via the same BK_AI_ASSISTANTS table, so it can only ever agree with it.
	// Only meaningful (and only ever sent) alongside referrer='ai'.
	getAiAssistantClass: function () {
		const attribution = new URLSearchParams(location.search);
		const attributedSource = (attribution.get('utm_source') || attribution.get('ref') || '').toLowerCase().trim();

		const byAttribution = BK_AI_ASSISTANTS.find((assistant) => assistant.attribution.includes(attributedSource));
		if (byAttribution) return byAttribution.slug;

		const ref = document.referrer;
		if (!ref) return '';

		let host;
		try {
			host = new URL(ref).hostname.toLowerCase().replace(/\.$/, '');
		} catch (e) {
			return '';
		}

		const byDomain = BK_AI_ASSISTANTS.find((assistant) =>
			assistant.domains.some((domain) => bk_matchesDomain(host, domain))
		);

		return byDomain ? byDomain.slug : '';
	},
	// DNT is a per-navigator legacy signal; globalPrivacyControl is the modern
	// successor (Sec-GPC). Either one means the visitor asked not to be tracked.
	hasPrivacySignal: function () {
		return navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true;
	},
	setPostView: function () {
		if (this.sent) return;
		this.sent = true;
		this.state = 'sent';

		const url = `${this.end_point}/set-post-views`;
		const data = {
			post_id: this.post_id,
			client_version: '2',
			measurement_version: 2,
		};

		// The view count itself stays anonymous either way (no IP/UA stored);
		// only the optional session dimensions are skipped for this visit.
		if (!bbk_post_view.respect_dnt || !this.hasPrivacySignal()) {
			data.viewport = this.getViewportBucket();
			data.referrer = this.getReferrerClass();

			if (data.referrer === 'ai') {
				const aiAssistant = this.getAiAssistantClass();
				if (aiAssistant) {
					data.ai_assistant = aiAssistant;
				}
			}
		}

		if (navigator.sendBeacon) {
			const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
			if (navigator.sendBeacon(url, blob)) return;
		}

		fetch(url, {
			method: 'POST',
			keepalive: true,
			body: JSON.stringify(data),
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
		}).catch(() => {
			// A lost response may still mean the server committed. Retrying is safe
			// because the durable server-side claim deduplicates the same visit.
			this.sent = false;
			this.state = 'pending';
		});
	},
};

window.addEventListener('load', () => bk_postview_main.init());
